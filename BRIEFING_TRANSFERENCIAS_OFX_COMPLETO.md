# Briefing — Detecção de transferências internas e categorização de movimentos no OFX (versão consolidada)

> **Este documento é a versão consolidada e final.** Contém o briefing 15 (transferências internas) + adendo BB (suporte ao formato Banco do Brasil) mesclados, sem duplicação. Aplicar de cabo a rabo neste documento — não precisa cruzar com outros.

---

## 1. Contexto e objetivos

Hoje todo movimento OFX (PIX, TED, boleto, débito) é classificado apenas pelo sinal do valor em `CREDITO` ou `DEBITO`. Isso gera dois problemas:

1. **Transferências entre contas próprias** (BTG ↔ Itaú ↔ BB do mesmo grupo, ou PIX entre sócio e empresa) são tratadas como receita/despesa, e o sistema sugere criar conta a pagar/receber. Errado contabilmente — não é receita nem despesa, é só remanejo de caixa.
2. **Aplicações automáticas** (BB Rende Fácil, MaxiInvest, Easy Invest), **rendimentos** e **tarifas** também caem como movimentos a conciliar manualmente, poluindo o fluxo.

Este briefing resolve ambos: adiciona detecção automática de natureza do movimento, registra pares cruzados entre bancos do grupo, e exclui essas linhas dos modais de conciliação.

### Diferenças entre OFX Itaú/BTG e Banco do Brasil (relevante pro parser)

| Aspecto | Itaú/BTG | Banco do Brasil |
|---|---|---|
| Categoria do movimento | Tudo no `<MEMO>` | `<NAME>` (categoria) + `<MEMO>` (detalhes) |
| MEMO vazio | Raro | Frequente (várias linhas com `<MEMO></MEMO>`) |
| Linha "Saldo do dia" | `<MEMO>SALDO DO DIA</MEMO>` | `<NAME>Saldo do dia</NAME>` + MEMO vazio |
| CNPJ no MEMO | Formatado: `62.432.028/0001-55` | Só dígitos: `62432028000155` |
| FITID | Curto numérico | Pode ter pontos: `11.848.430.597.722` |
| Aplicação automática | (cliente não tem) | Linhas "BB Rende Fácil" |
| PIX a pessoa física | MEMO com CPF | MEMO só com nome (sem CPF) |

O parser e a detecção precisam funcionar pra **os dois formatos** sem regressão.

---

## 2. Princípios de segurança

1. **Aditivo, não destrutivo**: criar coluna `COM_NATUREZA` com default `'NORMAL'`. Movimentos antigos continuam tratados como sempre.
2. **Default preserva comportamento atual**: se nenhuma detecção dispara, fica `NORMAL` e segue o fluxo de hoje.
3. **Reversível**: usuário pode marcar/desmarcar manualmente. Detecção automática é só sugestão.
4. **Auditoria**: cada par de transferência fica registrado com data de detecção, usuário e par cruzado.
5. **Não tocar em**: `saldoBancarioOfx` (transferência continua somando/subtraindo do saldo bancário de cada banco — está correto). `saldoErpConta` (já lê das contas a pagar/receber, naturalmente ignora movimentos OFX puros).
6. **Não tocar em**: `vincular_lancamento_existente`, `vincular_lancamentos_em_lote`, `cancelar_vinculo`, `cancelar_integracao`, `criar_pagar_em_lote`, `criar_receber_em_lote`. Todas preservadas.
7. **Auto-populate inicial**: copiar CNPJs já cadastrados em `tb_empresa` pra `tb_grupo_documento` automaticamente.
8. **Detecção heurística é conservadora**: na dúvida, mantém `NORMAL`. Match cruzado é o que confirma transferência interna como par concreto.
9. **Detecção por nome só dispara em PIX/TED/DOC**: NÃO marca como transferência só porque MEMO de um boleto pago menciona um nome conhecido por coincidência.
10. **Regex de CNPJ usa lookbehind/lookahead** pra não confundir FITID com pontos (formato BB) com CNPJ.

---

## 3. Plano de edição

### Fase A — Migration

Arquivo novo: `migrations/2026_06_09_transferencias_internas.sql`

```sql
-- 1) Cadastro de documentos do grupo (CNPJs/CPFs que representam "casa")
CREATE TABLE IF NOT EXISTS tb_grupo_documento (
    GDO_CODIGO_PK BIGINT NOT NULL AUTO_INCREMENT,
    GDO_TIPO ENUM('PJ','PF') NOT NULL,
    GDO_DOCUMENTO VARCHAR(20) NOT NULL,        -- só dígitos, sem formatação
    GDO_NOME VARCHAR(200) NOT NULL,
    GDO_STATUS ENUM('ATIVO','INATIVO') NOT NULL DEFAULT 'ATIVO',
    GDO_OBSERVACAO TEXT NULL,
    GDO_DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    GDO_USUARIO VARCHAR(120) NULL,
    PRIMARY KEY (GDO_CODIGO_PK),
    UNIQUE KEY uk_gdo_doc (GDO_DOCUMENTO),
    INDEX idx_gdo_status (GDO_STATUS)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Colunas novas em movimentos OFX para classificar natureza
-- ENUM já criado com TODOS os 5 valores (NORMAL, TRANSFERENCIA_INTERNA, APLICACAO, RENDIMENTO, TARIFA)
ALTER TABLE tb_conciliacao_ofx_movimento
    ADD COLUMN COM_NATUREZA ENUM(
        'NORMAL',
        'TRANSFERENCIA_INTERNA',
        'APLICACAO',
        'RENDIMENTO',
        'TARIFA'
    ) NOT NULL DEFAULT 'NORMAL' AFTER COM_TIPO,
    ADD COLUMN COM_DOCUMENTO_CONTRAPARTE VARCHAR(20) NULL AFTER COM_NATUREZA,
    ADD INDEX idx_com_natureza (COM_NATUREZA);

-- 3) Tabela de pares cruzados de transferência interna
CREATE TABLE IF NOT EXISTS tb_transferencia_interna (
    TFI_CODIGO_PK BIGINT NOT NULL AUTO_INCREMENT,
    TFI_MOV_ORIGEM_FK BIGINT NOT NULL,          -- débito (saída)
    TFI_MOV_DESTINO_FK BIGINT NOT NULL,         -- crédito (entrada)
    TFI_VALOR DECIMAL(15,2) NOT NULL,
    TFI_DATA_DETECCAO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    TFI_MODO_DETECCAO ENUM('AUTOMATICO','MANUAL') NOT NULL DEFAULT 'AUTOMATICO',
    TFI_STATUS ENUM('ATIVO','CANCELADO') NOT NULL DEFAULT 'ATIVO',
    TFI_USUARIO VARCHAR(120) NULL,
    PRIMARY KEY (TFI_CODIGO_PK),
    UNIQUE KEY uk_tfi_origem (TFI_MOV_ORIGEM_FK, TFI_STATUS),
    UNIQUE KEY uk_tfi_destino (TFI_MOV_DESTINO_FK, TFI_STATUS),
    INDEX idx_tfi_status (TFI_STATUS)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Auto-populate: copia CNPJs das empresas ativas pro grupo
-- IMPORTANTE: REGEXP_REPLACE exige MySQL 8.0+.
-- Se for 5.7, substituir por REPLACE(REPLACE(REPLACE(EMP_CNPJ,'.',''),'/',''),'-','')
INSERT INTO tb_grupo_documento (GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_USUARIO)
SELECT 'PJ',
       REGEXP_REPLACE(EMP_CNPJ, '[^0-9]', ''),
       COALESCE(NULLIF(EMP_NOME_FANTASIA, ''), EMP_RAZAO_SOCIAL),
       'auto-populate (migration)'
FROM tb_empresa
WHERE COALESCE(EMP_STATUS, 'ATIVO') = 'ATIVO'
  AND EMP_CNPJ IS NOT NULL
  AND LENGTH(REGEXP_REPLACE(EMP_CNPJ, '[^0-9]', '')) IN (11, 14)
ON DUPLICATE KEY UPDATE GDO_NOME = VALUES(GDO_NOME);
```

> **Antes de rodar**: confirme `SELECT VERSION();`. Se MySQL 5.7, troque os 3 `REGEXP_REPLACE(EMP_CNPJ, '[^0-9]', '')` por `REPLACE(REPLACE(REPLACE(EMP_CNPJ,'.',''),'/',''),'-','')`.

> Confirmar nome das colunas em `tb_empresa` via INFORMATION_SCHEMA. Se `EMP_CNPJ`/`EMP_STATUS`/`EMP_NOME_FANTASIA`/`EMP_RAZAO_SOCIAL` forem diferentes, ajustar.

### Fase B — Backend: CRUD do grupo de documentos

**Arquivo novo:** `app/endpoints/grupo_documentos.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

$acao = $_REQUEST['acao'] ?? '';

try {
    if ($acao === 'listar') {
        $st = $pdo->query("
            SELECT GDO_CODIGO_PK, GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_STATUS, GDO_OBSERVACAO,
                   DATE_FORMAT(GDO_DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data_cadastro_br
            FROM tb_grupo_documento
            ORDER BY GDO_TIPO, GDO_NOME
        ");
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'salvar') {
        $id   = (int)($_POST['id'] ?? 0);
        $tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
        $doc  = preg_replace('/\D+/', '', (string)($_POST['documento'] ?? ''));
        $nome = trim((string)($_POST['nome'] ?? ''));
        $obs  = trim((string)($_POST['observacao'] ?? ''));

        if (!in_array($tipo, ['PJ','PF'], true)) json_out(['ok'=>false,'msg'=>'Tipo inválido'], 422);
        if (!in_array(strlen($doc), [11, 14], true)) json_out(['ok'=>false,'msg'=>'Documento inválido (precisa 11 ou 14 dígitos)'], 422);
        if ($nome === '') json_out(['ok'=>false,'msg'=>'Nome obrigatório'], 422);

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE tb_grupo_documento
                                 SET GDO_TIPO=?, GDO_DOCUMENTO=?, GDO_NOME=?, GDO_OBSERVACAO=?
                                 WHERE GDO_CODIGO_PK=?");
            $st->execute([$tipo, $doc, $nome, $obs, $id]);
        } else {
            $st = $pdo->prepare("INSERT INTO tb_grupo_documento (GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_OBSERVACAO, GDO_USUARIO)
                                 VALUES (?, ?, ?, ?, ?)");
            $st->execute([$tipo, $doc, $nome, $obs, $usuario]);
        }
        json_out(['ok' => true, 'msg' => 'Salvo']);
    }

    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE tb_grupo_documento
                       SET GDO_STATUS = IF(GDO_STATUS = 'ATIVO', 'INATIVO', 'ATIVO')
                       WHERE GDO_CODIGO_PK = ?")->execute([$id]);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    json_out(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()], 500);
}
```

**Tela:** `app/grupo_documentos.php` — simples, tabela + modal de cadastro seguindo padrão visual de `app/empresas.php` ou `app/clientes.php`. Pode ser polida em uma segunda iteração; o essencial é o CRUD backend funcionar.

### Fase C — Backend: parser ajustado + detecção de natureza durante o import

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, action `importar_ofx` (~linhas 1030–1088).

**Antes do `foreach ($movimentos as $bloco)`**, pré-carregar dados do grupo:

```php
// Pré-carrega documentos e nomes do grupo
$stGrupo = $pdo->query("SELECT GDO_DOCUMENTO, GDO_NOME FROM tb_grupo_documento WHERE GDO_STATUS = 'ATIVO'");
$docsGrupo = [];
$nomesGrupo = [];
foreach ($stGrupo->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $docsGrupo[(string)$g['GDO_DOCUMENTO']] = true;
    $nome = trim(mb_strtoupper((string)$g['GDO_NOME'], 'UTF-8'));
    // Só considera nomes com 5+ caracteres pra evitar falsos positivos
    if (mb_strlen($nome) >= 5) {
        $nomesGrupo[] = $nome;
    }
}

// Regex de CNPJ/CPF com proteção contra falso positivo em FITID do BB
$cnpjCpfPattern = '/(?:'
    . '\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}'   // CNPJ formatado
    . '|\d{3}\.\d{3}\.\d{3}-\d{2}'         // CPF formatado
    . '|(?<![\d.])\d{14}(?![\d.])'         // CNPJ só dígitos (14)
    . '|(?<![\d.])\d{11}(?![\d.])'         // CPF só dígitos (11)
    . ')/';
```

**Substituir o bloco que extrai campos e define `$tipo`** (linhas ~1030–1088) por:

```php
foreach ($movimentos as $bloco) {
    preg_match('/<DTPOSTED>([^\r\n<]+)/i', $bloco, $mData);
    preg_match('/<TRNAMT>([^\r\n<]+)/i', $bloco, $mValor);
    preg_match('/<MEMO>([^<]*)/i', $bloco, $mMemo);   // [^<]* aceita MEMO vazio
    preg_match('/<NAME>([^<]*)/i', $bloco, $mName);
    preg_match('/<FITID>([^\r\n<]+)/i', $bloco, $mFitid);
    preg_match('/<TRNTYPE>([^\r\n<]+)/i', $bloco, $mTipoOfx);

    $dataBruta = trim((string)($mData[1] ?? ''));
    $valor = (float)trim((string)($mValor[1] ?? 0));
    $memoCru = trim((string)($mMemo[1] ?? ''));
    $nameCru = trim((string)($mName[1] ?? ''));
    $trnType = strtoupper(trim((string)($mTipoOfx[1] ?? '')));
    $documento = trim((string)($mFitid[1] ?? ''));

    if (strlen($dataBruta) < 8) {
        continue;
    }

    // Descrição final combina NAME e MEMO. BB usa NAME pra categoria; Itaú/BTG joga tudo no MEMO.
    if ($nameCru !== '' && $memoCru !== '') {
        $descricao = $nameCru . ' · ' . $memoCru;
    } elseif ($nameCru !== '') {
        $descricao = $nameCru;
    } elseif ($memoCru !== '') {
        $descricao = $memoCru;
    } else {
        $descricao = 'Movimento OFX';
    }

    // Filtra linhas de saldo: olha NAME (BB) e MEMO (Itaú)
    $descNorm = mb_strtoupper($descricao, 'UTF-8');
    $nameNorm = mb_strtoupper($nameCru, 'UTF-8');
    $memoNorm = mb_strtoupper($memoCru, 'UTF-8');

    $padraoSaldo = '/^SALDO\s+(ANTERIOR|TOTAL|EM\s+CONTA|DISPON[IÍ]VEL|DO\s+DIA|FINAL|INICIAL)/u';
    if (preg_match($padraoSaldo, $memoNorm)
        || preg_match($padraoSaldo, $nameNorm)
        || $memoNorm === 'SALDO'
        || $nameNorm === 'SALDO ANTERIOR'
        || $nameNorm === 'SALDO DO DIA') {
        continue;
    }
    // Também ignora movimentos com valor zero E categoria de saldo
    if (abs($valor) < 0.005 && (str_contains($nameNorm, 'SALDO') || str_contains($memoNorm, 'SALDO'))) {
        continue;
    }

    $dataSql = substr($dataBruta, 0, 4) . '-' . substr($dataBruta, 4, 2) . '-' . substr($dataBruta, 6, 2);
    $hashFingerprint = hash('sha256', $dataSql . '|' . $valor . '|' . $descricao . '|' . $documento);
    $hashLegado = hash('sha256', $bancoFk . '|' . $contaRef . '|' . $dataSql . '|' . $valor . '|' . $descricao . '|' . $documento);

    $stDup->execute([':hash_new' => $hashFingerprint, ':hash_legado' => $hashLegado]);
    if ($stDup->fetch()) {
        $stDup->closeCursor();
        continue;
    }
    $stDup->closeCursor();

    $saldoAtual += $valor;

    if ($valor >= 0) {
        $entradas += $valor;
        $tipo = 'CREDITO';
    } else {
        $saidas += abs($valor);
        $tipo = 'DEBITO';
    }

    // ========== Detecção de natureza ==========
    $natureza = 'NORMAL';
    $docContraparte = null;

    // 1) Por documento (CNPJ/CPF formatado OU sem formatação)
    if (preg_match($cnpjCpfPattern, $descricao, $mDoc)) {
        $docLimpo = preg_replace('/\D+/', '', $mDoc[0]);
        if (in_array(strlen($docLimpo), [11, 14], true)) {
            $docContraparte = $docLimpo;
            if (isset($docsGrupo[$docLimpo])) {
                $natureza = 'TRANSFERENCIA_INTERNA';
            }
        }
    }

    // 2) Por nome (só pra PIX/TED/DOC, quando documento não veio)
    if ($natureza === 'NORMAL' && $nameCru !== ''
        && preg_match('/PIX|TED|DOC|TRANSFER/iu', $nameCru)) {
        foreach ($nomesGrupo as $nomeGrupo) {
            if (mb_stripos($descricao, $nomeGrupo) !== false) {
                $natureza = 'TRANSFERENCIA_INTERNA';
                break;
            }
        }
    }

    // 3) Aplicação automática do mesmo banco (Rende Fácil, MaxiInvest, etc.)
    if ($natureza === 'NORMAL') {
        $padraoAplicacao = '/RENDE\s+F[AÁ]CIL|APLIC(A[CÇ][AÃ]O)?\s+AUT|RESGATE\s+AUT|MAXI\s*INVEST|EASY\s*INVEST|FUNDO\s+AUTOM|CDB\s+AUT/iu';
        if (preg_match($padraoAplicacao, $nameCru) || preg_match($padraoAplicacao, $descricao)) {
            $natureza = 'APLICACAO';
        }
    }

    // 4) Rendimento de aplicação (juros pagos)
    if ($natureza === 'NORMAL') {
        if (preg_match('/REND(IMENTO)?\s+PAGO\s+APLIC|REND(IMENTOS)?\s+POUPAN/iu', $descricao . ' ' . $nameCru)) {
            $natureza = 'RENDIMENTO';
        }
    }

    // 5) Tarifa bancária
    if ($natureza === 'NORMAL') {
        if (preg_match('/TARIFA|D[EÉ]BITO\s+SERVI[CÇ]O|IOF|TX\s+ANUIDADE|TAR\.?\s+AGRUPADAS/iu', $descricao . ' ' . $nameCru)) {
            $natureza = 'TARIFA';
        }
    }
    // ========== Fim detecção ==========

    $stMov->execute([
        ':importacao_fk' => $importacaoFk,
        ':banco_fk' => $bancoFk,
        ':conta_ref' => $contaRef,
        ':data_movimento' => $dataSql,
        ':documento' => $documento,
        ':descricao' => mb_substr($descricao, 0, 255),
        ':valor' => $valor,
        ':saldo_apos' => $saldoAtual,
        ':tipo' => $tipo,
        ':natureza' => $natureza,
        ':doc_contraparte' => $docContraparte,
        ':hash' => $hashFingerprint,
    ]);

    $incluidos++;
}
```

E ajustar o `$stMov` (INSERT) pra incluir as 2 colunas novas:

```php
$stMov = $pdo->prepare("
    INSERT INTO tb_conciliacao_ofx_movimento (
        COM_IMPORTACAO_FK, COM_BANCO_FK, COM_CONTA_REF,
        COM_DATA_MOVIMENTO, COM_DOCUMENTO, COM_DESCRICAO,
        COM_VALOR, COM_SALDO_APOS, COM_TIPO,
        COM_NATUREZA, COM_DOCUMENTO_CONTRAPARTE,
        COM_HASH, COM_STATUS, COM_CONCILIADO
    ) VALUES (
        :importacao_fk, :banco_fk, :conta_ref,
        :data_movimento, :documento, :descricao,
        :valor, :saldo_apos, :tipo,
        :natureza, :doc_contraparte,
        :hash, 'IMPORTADO', 'NAO'
    )
");
```

### Fase D — Backend: match cruzado entre bancos

Nova action `detectar_pares_transferencia` em `app/endpoints/conciliacao_bancaria.php`:

```php
if ($acao === 'detectar_pares_transferencia') {
    $pdo->beginTransaction();
    try {
        $stSaidas = $pdo->query("
            SELECT m.COM_CODIGO_PK, m.COM_BANCO_FK, m.COM_DATA_MOVIMENTO,
                   m.COM_VALOR, m.COM_DOCUMENTO_CONTRAPARTE
            FROM tb_conciliacao_ofx_movimento m
            WHERE m.COM_TIPO = 'DEBITO'
              AND m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
              AND NOT EXISTS (
                  SELECT 1 FROM tb_transferencia_interna t
                  WHERE t.TFI_MOV_ORIGEM_FK = m.COM_CODIGO_PK AND t.TFI_STATUS = 'ATIVO'
              )
        ");
        $saidas = $stSaidas->fetchAll(PDO::FETCH_ASSOC);

        $stEntrada = $pdo->prepare("
            SELECT m.COM_CODIGO_PK
            FROM tb_conciliacao_ofx_movimento m
            WHERE m.COM_TIPO = 'CREDITO'
              AND m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
              AND m.COM_BANCO_FK <> :banco_origem
              AND ABS(ABS(m.COM_VALOR) - :valor) < 0.01
              AND ABS(DATEDIFF(m.COM_DATA_MOVIMENTO, :data)) <= 1
              AND NOT EXISTS (
                  SELECT 1 FROM tb_transferencia_interna t
                  WHERE t.TFI_MOV_DESTINO_FK = m.COM_CODIGO_PK AND t.TFI_STATUS = 'ATIVO'
              )
            LIMIT 1
        ");

        $stIns = $pdo->prepare("
            INSERT INTO tb_transferencia_interna
                (TFI_MOV_ORIGEM_FK, TFI_MOV_DESTINO_FK, TFI_VALOR, TFI_MODO_DETECCAO, TFI_USUARIO)
            VALUES (?, ?, ?, 'AUTOMATICO', ?)
        ");

        $usuario = (string)($_SESSION['user_nome'] ?? 'Sistema');
        $criados = 0;

        foreach ($saidas as $s) {
            $stEntrada->execute([
                ':banco_origem' => $s['COM_BANCO_FK'],
                ':valor' => abs((float)$s['COM_VALOR']),
                ':data' => $s['COM_DATA_MOVIMENTO'],
            ]);
            $destino = $stEntrada->fetch(PDO::FETCH_ASSOC);
            if (!$destino) continue;

            $stIns->execute([
                $s['COM_CODIGO_PK'],
                $destino['COM_CODIGO_PK'],
                abs((float)$s['COM_VALOR']),
                $usuario,
            ]);
            $criados++;
        }

        $pdo->commit();
        json_out(['ok' => true, 'pares_criados' => $criados]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'msg' => 'Falha: ' . $e->getMessage()], 500);
    }
}
```

**Chamar via fetch no front** após import bem-sucedido (não dentro do PHP do import — evita travar o import se a detecção falhar).

### Fase E — Backend: excluir naturezas especiais dos modais de revisão

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, actions `debitos_orfaos` (~linha 1280) e `creditos_orfaos` (~linha 1576).

Na query principal de cada uma, adicionar:

```sql
AND m.COM_NATUREZA NOT IN ('TRANSFERENCIA_INTERNA', 'APLICACAO', 'TARIFA', 'RENDIMENTO')
```

Idem em `lancamentos_disponiveis`, `buscar_lancamento_existente` e qualquer outra ação que mostre movimentos OFX órfãos. Nenhuma dessas 4 naturezas é receita/despesa real — não deve virar conta a pagar/receber.

### Fase F — Backend: 3 actions de visualização e manejo

```php
// Listar todos pares de transferência (ativos)
if ($acao === 'listar_transferencias_internas') {
    $st = $pdo->query("
        SELECT t.TFI_CODIGO_PK AS id,
               t.TFI_VALOR AS valor,
               DATE_FORMAT(t.TFI_DATA_DETECCAO, '%d/%m/%Y %H:%i') AS data_deteccao,
               t.TFI_MODO_DETECCAO AS modo,
               mo.COM_CODIGO_PK AS origem_id, mo.COM_DATA_MOVIMENTO AS origem_data,
               mo.COM_DESCRICAO AS origem_desc, bo.BAN_APELIDO AS origem_banco,
               md.COM_CODIGO_PK AS destino_id, md.COM_DATA_MOVIMENTO AS destino_data,
               md.COM_DESCRICAO AS destino_desc, bd.BAN_APELIDO AS destino_banco
        FROM tb_transferencia_interna t
        INNER JOIN tb_conciliacao_ofx_movimento mo ON mo.COM_CODIGO_PK = t.TFI_MOV_ORIGEM_FK
        INNER JOIN tb_conciliacao_ofx_movimento md ON md.COM_CODIGO_PK = t.TFI_MOV_DESTINO_FK
        LEFT JOIN tb_banco bo ON bo.BAN_ID = mo.COM_BANCO_FK
        LEFT JOIN tb_banco bd ON bd.BAN_ID = md.COM_BANCO_FK
        WHERE t.TFI_STATUS = 'ATIVO'
        ORDER BY t.TFI_DATA_DETECCAO DESC
        LIMIT 200
    ");
    json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// Listar transferências detectadas SEM par cruzado
if ($acao === 'listar_transferencias_sem_par') {
    $st = $pdo->query("
        SELECT m.COM_CODIGO_PK AS id, m.COM_DATA_MOVIMENTO AS data,
               m.COM_VALOR AS valor, m.COM_DESCRICAO AS descricao,
               m.COM_TIPO AS tipo, b.BAN_APELIDO AS banco,
               m.COM_DOCUMENTO_CONTRAPARTE AS doc_contraparte
        FROM tb_conciliacao_ofx_movimento m
        LEFT JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
        WHERE m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
          AND NOT EXISTS (
            SELECT 1 FROM tb_transferencia_interna t
            WHERE (t.TFI_MOV_ORIGEM_FK = m.COM_CODIGO_PK OR t.TFI_MOV_DESTINO_FK = m.COM_CODIGO_PK)
              AND t.TFI_STATUS = 'ATIVO'
          )
        ORDER BY m.COM_DATA_MOVIMENTO DESC
        LIMIT 200
    ");
    json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// Override manual de natureza
if ($acao === 'alterar_natureza_movimento') {
    $movId    = (int)($_POST['movimento_fk'] ?? 0);
    $natureza = strtoupper((string)($_POST['natureza'] ?? 'NORMAL'));
    if (!in_array($natureza, ['NORMAL','TRANSFERENCIA_INTERNA','APLICACAO','RENDIMENTO','TARIFA'], true)) {
        json_out(['ok'=>false,'msg'=>'Natureza inválida'], 422);
    }
    $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento SET COM_NATUREZA = ? WHERE COM_CODIGO_PK = ?")
        ->execute([$natureza, $movId]);

    if ($natureza === 'NORMAL') {
        $pdo->prepare("UPDATE tb_transferencia_interna
                       SET TFI_STATUS = 'CANCELADO'
                       WHERE (TFI_MOV_ORIGEM_FK = ? OR TFI_MOV_DESTINO_FK = ?)
                         AND TFI_STATUS = 'ATIVO'")
            ->execute([$movId, $movId]);
    }
    json_out(['ok' => true]);
}

// Vincular par manualmente (origem + destino selecionados pelo usuário)
if ($acao === 'vincular_par_manual') {
    $origemId  = (int)($_POST['origem_id'] ?? 0);
    $destinoId = (int)($_POST['destino_id'] ?? 0);
    if ($origemId <= 0 || $destinoId <= 0 || $origemId === $destinoId) {
        json_out(['ok'=>false,'msg'=>'IDs inválidos'], 422);
    }

    $stV = $pdo->prepare("SELECT COM_CODIGO_PK, COM_TIPO, COM_VALOR, COM_NATUREZA
                          FROM tb_conciliacao_ofx_movimento
                          WHERE COM_CODIGO_PK IN (?, ?)");
    $stV->execute([$origemId, $destinoId]);
    $rows = $stV->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2) json_out(['ok'=>false,'msg'=>'Movimentos não encontrados'], 404);

    $origem  = $rows[0]['COM_CODIGO_PK'] == $origemId ? $rows[0] : $rows[1];
    $destino = $rows[0]['COM_CODIGO_PK'] == $destinoId ? $rows[0] : $rows[1];

    if ($origem['COM_TIPO'] !== 'DEBITO' || $destino['COM_TIPO'] !== 'CREDITO') {
        json_out(['ok'=>false,'msg'=>'Origem deve ser débito, destino deve ser crédito'], 422);
    }
    if (abs(abs((float)$origem['COM_VALOR']) - (float)$destino['COM_VALOR']) > 0.01) {
        json_out(['ok'=>false,'msg'=>'Valores não batem'], 422);
    }

    $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento SET COM_NATUREZA='TRANSFERENCIA_INTERNA' WHERE COM_CODIGO_PK IN (?, ?)")
        ->execute([$origemId, $destinoId]);

    $pdo->prepare("
        INSERT INTO tb_transferencia_interna
            (TFI_MOV_ORIGEM_FK, TFI_MOV_DESTINO_FK, TFI_VALOR, TFI_MODO_DETECCAO, TFI_USUARIO)
        VALUES (?, ?, ?, 'MANUAL', ?)
    ")->execute([$origemId, $destinoId, abs((float)$origem['COM_VALOR']),
                 (string)($_SESSION['user_nome'] ?? 'Sistema')]);
    json_out(['ok'=>true,'msg'=>'Par vinculado.']);
}
```

### Fase G — Frontend: aba "Transferências internas" na Conciliação

**Arquivo:** `app/conciliacao_bancaria.php`. Botão no topo (perto de "Revisar vínculos OFX", "Conferir vínculos"):

```html
<button type="button" class="btn btn-sm btn-outline-info" id="btnTransferenciasInternas">
    <i class="bi bi-arrow-left-right me-1"></i>Transferências internas
</button>
```

Modal com 3 abas:

```html
<div class="modal fade" id="modalTransferenciasInternas" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right me-2"></i>Transferências entre contas próprias</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#abaPares">Pares casados</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#abaSemPar">Sem par</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#abaDocs">CNPJs do grupo</a></li>
                </ul>
                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="abaPares">
                        <button class="btn btn-sm btn-warning mb-2" id="btnRodarDeteccao">
                            <i class="bi bi-arrow-clockwise me-1"></i>Rodar detecção agora
                        </button>
                        <table class="table table-sm">
                            <thead><tr><th>Origem (saída)</th><th>Destino (entrada)</th><th class="text-end">Valor</th><th>Modo</th><th>Detectado em</th></tr></thead>
                            <tbody id="tiPares"></tbody>
                        </table>
                    </div>
                    <div class="tab-pane fade" id="abaSemPar">
                        <p class="small text-muted">Movimentos suspeitos de transferência (CNPJ/nome do grupo no MEMO) que ainda não casaram com um par no outro banco.</p>
                        <table class="table table-sm">
                            <thead><tr><th>Banco</th><th>Data</th><th>Descrição</th><th class="text-end">Valor</th><th>Ação</th></tr></thead>
                            <tbody id="tiSemPar"></tbody>
                        </table>
                    </div>
                    <div class="tab-pane fade" id="abaDocs">
                        <p class="small text-muted">CNPJs/CPFs cadastrados como "do grupo". Movimentos OFX com esses documentos no MEMO são marcados como transferência interna.</p>
                        <a href="grupo_documentos.php" class="btn btn-sm btn-primary mb-2">
                            <i class="bi bi-pencil me-1"></i>Gerenciar cadastro
                        </a>
                        <table class="table table-sm">
                            <thead><tr><th>Tipo</th><th>Documento</th><th>Nome</th><th>Status</th></tr></thead>
                            <tbody id="tiDocs"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
```

JS: handlers para carregar cada aba via fetch das 4 actions da Fase F.

### Fase H — Frontend: badges no extrato espelho

No template de cada linha do extrato (procurar onde tem `COM_STATUS` "Conciliado"/"Importado"), adicionar badges por natureza:

```js
${r.natureza === 'TRANSFERENCIA_INTERNA' ? '<span class="badge bg-info-subtle text-info ms-1">Transf. interna</span>' : ''}
${r.natureza === 'APLICACAO' ? '<span class="badge bg-warning-subtle text-warning-emphasis ms-1">Aplicação</span>' : ''}
${r.natureza === 'RENDIMENTO' ? '<span class="badge bg-success-subtle text-success ms-1">Rendimento</span>' : ''}
${r.natureza === 'TARIFA' ? '<span class="badge bg-secondary-subtle text-secondary ms-1">Tarifa</span>' : ''}
```

E garantir que a action `listar_extrato` (ou equivalente) retorna `COM_NATUREZA AS natureza` no SELECT.

### Fase I — BI/Dashboard: validação

Grep em `app/endpoints/bi.php` e `app/index.php` por `tb_conciliacao_ofx_movimento`. Se houver SUM ou COUNT direto desses movimentos pra calcular receita/despesa, adicionar `AND COM_NATUREZA = 'NORMAL'`.

Esperado: BI e Dashboard leem só de `tb_contas_pagar`/`tb_contas_receber`, então essa fase provavelmente é só validação. Reporte achado.

---

## 4. Critérios de aceite (25 testes)

### Validação base

1. `php -l` OK em todos os arquivos alterados.
2. **Migration aplicada**: tabelas `tb_grupo_documento` e `tb_transferencia_interna` existem. Colunas `COM_NATUREZA` e `COM_DOCUMENTO_CONTRAPARTE` existem em `tb_conciliacao_ofx_movimento`. ENUM com os 5 valores corretos.
3. **Auto-populate**: após migration, `tb_grupo_documento` já contém CNPJs das empresas cadastradas em `tb_empresa` (verificar com `SELECT * FROM tb_grupo_documento`).

### Detecção em OFX Itaú/BTG (formato MEMO único)

4. Importar OFX do Itaú com PIX cujo MEMO tenha CNPJ formatado de empresa do grupo (ex: `62.432.028/0001-55`) → `COM_NATUREZA = 'TRANSFERENCIA_INTERNA'`.
5. Importar OFX com PIX a fornecedor genérico (CNPJ fora do grupo) → `COM_NATUREZA = 'NORMAL'`.
6. **Regressão Itaú**: importar `OFX/Extrato_9861_993062_26-04-2026 itau.ofx` antigo — todos os movimentos com CNPJ não-grupo entram como `NORMAL`. Nenhum erro no parser.
7. **Regressão BTG**: importar `OFX/BTG 50_012661609.ofx` antigo — idem.

### Detecção em OFX Banco do Brasil

8. **Importar OFX do BB** (`BB 01-06 a 07-06.ofx`):
   - Linhas "Saldo do dia" e "Saldo Anterior" **NÃO** aparecem como movimento R$ 0,00.
   - Aproximadamente 14 movimentos reais importados (não 18+ com saldos).
9. **Descrição combinada NAME + MEMO**:
   - PIX recebido R$ 23.481,33 (01/06) tem descrição `"Pix - Recebido · 01/06 18:48 62432028000155 AVS APOIO A"`.
   - Cobrança R$ 500,90 (01/06) tem descrição `"Cobrança"` (NAME só, MEMO vazio — não cai em "Movimento OFX").
10. **Detecção por CNPJ sem pontuação**: PIX R$ 23.481,33 com `62432028000155` no MEMO → `TRANSFERENCIA_INTERNA` (62432028000155 é CNPJ da AVS, do grupo).
11. **Detecção por nome (sócio sem CPF no MEMO)**: PIX enviado R$ 22.500 pra `DIOGO ANDRE DA SILVA` (sem CPF) → `TRANSFERENCIA_INTERNA`. **Pré-requisito**: cadastrar "DIOGO ANDRE DA SILVA" tipo PF em `tb_grupo_documento` antes de testar.
12. **Detecção APLICACAO**: as 3 linhas "BB Rende Fácil" do OFX → `COM_NATUREZA = 'APLICACAO'`.
13. **Detecção TARIFA**: "Débito Serviço Cobrança" com MEMO "Tar. agrupadas" → `COM_NATUREZA = 'TARIFA'`.

### Match cruzado

14. **Match cruzado entre bancos**: importar OFX do BTG com débito R$ 1.000 pra AVS no dia X, e OFX do Itaú com crédito R$ 1.000 da ASSESCCONT no dia X → rodar `detectar_pares_transferencia` → 1 par criado em `tb_transferencia_interna`. Aba "Pares casados" mostra o par.

### Exclusão dos modais

15. **Movimentos `TRANSFERENCIA_INTERNA` excluídos**: não aparecem em "Débitos do extrato sem lançamento" nem "Créditos do extrato sem lançamento".
16. **Movimentos `APLICACAO`/`RENDIMENTO`/`TARIFA` também excluídos**: mesma regra aplicada (NOT IN dos 4).
17. **Movimentos `NORMAL` continuam aparecendo** normalmente para conciliação.

### UI

18. **Badge azul "Transf. interna"** aparece em linhas TRANSFERENCIA_INTERNA do extrato espelho.
19. **Badge amarelo "Aplicação"** aparece em linhas APLICACAO.
20. **Badges "Rendimento" (verde) e "Tarifa" (cinza)** aparecem corretamente.

### Override e manejo

21. **Override manual**: marcar movimento NORMAL como TRANSFERENCIA_INTERNA via `alterar_natureza_movimento` funciona.
22. **Desmarcar**: marcar de TRANSFERENCIA_INTERNA pra NORMAL cancela automaticamente o par em `tb_transferencia_interna`.
23. **Vinculação manual de par**: na aba "Sem par", escolher débito + crédito de bancos diferentes → action `vincular_par_manual` cria par.

### Integridade

24. **Saldo bancário preservado**: `saldoBancarioOfx` continua somando/subtraindo movimentos (incluindo TRANSFERENCIA_INTERNA e APLICACAO — o banco real foi movimentado).
25. **Saldo ERP não afetado**: `saldoErpConta` continua igual (não lê de OFX direto).

---

## 5. Cuidados na aplicação

1. **Cadastrar manualmente os sócios** em `tb_grupo_documento` (tipo PF) após auto-populate. Sem isso, detecção por nome no BB não funciona pros PIX entre empresa e sócios.
2. **Confirmar versão MySQL** antes de rodar migration. Se 5.7, trocar `REGEXP_REPLACE` por `REPLACE` aninhado.
3. **Detecção por nome só em PIX/TED/DOC**: NÃO marca como transferência só porque MEMO de um boleto pago menciona um nome conhecido por coincidência.
4. **Regex de CNPJ usa lookbehind/lookahead** `(?<![\d.])` e `(?![\d.])`: importante porque FITID do BB pode ter pontos (ex: `11.848.430.597.722`). Sem essa proteção, a regex acharia "11848430597722" e tentaria tratar como CNPJ.
5. **Match cruzado via fetch no front**, não dentro do PHP do import. Se a detecção falhar, o import não é afetado.

---

## 6. Arquivos afetados

| Arquivo | Mudança |
|---|---|
| `migrations/2026_06_09_transferencias_internas.sql` | Criar (Fase A) |
| `app/endpoints/conciliacao_bancaria.php` | Parser ajustado + 4 detecções + 5 actions novas (Fases C, D, E, F) |
| `app/endpoints/grupo_documentos.php` | Criar (Fase B — CRUD) |
| `app/conciliacao_bancaria.php` | Botão + modal "Transferências internas" + badges no extrato (Fases G, H) |
| `app/grupo_documentos.php` | Criar (Fase B — tela de cadastro) |
| `app/endpoints/bi.php` | Validar (Fase I — provavelmente nenhuma mudança) |
| `app/index.php` | Validar (Fase I — idem) |

Total estimado: ~760 linhas adicionadas, zero removidas. Risco baixo.

---

## 7. Plano de rollback

1. `DROP TABLE tb_transferencia_interna;`
2. `DROP TABLE tb_grupo_documento;`
3. `ALTER TABLE tb_conciliacao_ofx_movimento DROP COLUMN COM_NATUREZA, DROP COLUMN COM_DOCUMENTO_CONTRAPARTE, DROP INDEX idx_com_natureza;`
4. `git revert` dos arquivos.

Sem perda de dados de receita/despesa — `tb_contas_pagar`/`tb_contas_receber` não são tocados.

---

## 8. Aviso pro cliente após aplicar

> "O sistema agora reconhece automaticamente:
>
> 1. **Transferências entre contas próprias** — quando você faz PIX da AVS (Itaú) pra ASSESCCONT (BTG), por exemplo, o sistema identifica como transferência interna e **não pede pra criar conta a receber/pagar**. Fica registrado só como remanejo de caixa, com etiqueta azul "Transf. interna" no extrato.
>
> 2. **Aplicações automáticas** (BB Rende Fácil, MaxiInvest, etc.) — viram movimentos com etiqueta amarela "Aplicação". Também não pedem conciliação.
>
> 3. **Rendimentos de aplicação e tarifas bancárias** — identificados separadamente.
>
> A detecção usa o cadastro de CNPJs e CPFs do grupo (acessível em **Conciliação → Transferências internas → CNPJs do grupo**). Já populamos automaticamente com os CNPJs das empresas cadastradas. **Adicione manualmente CPFs de sócios** (Diogo, Tales, etc.) se quiser que os PIX entre eles e a empresa também sejam tratados como transferência interna.
>
> O sistema agora também entende o formato do Banco do Brasil — categorias (PIX recebido, Cobrança, Rende Fácil) ficam visíveis no extrato."

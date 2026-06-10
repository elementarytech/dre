# Briefing 15 — Detecção de transferências internas entre contas do grupo no OFX

> Hoje todo movimento OFX (PIX/TED/DOC) é tratado como receita ou despesa genérica. Quando o cliente movimenta dinheiro entre suas próprias contas (BTG ↔ Itaú do mesmo CNPJ, ou de um sócio pra empresa), o sistema sugere conciliar essas linhas como conta a pagar/receber, o que é **errado contabilmente** — não é receita nem despesa, é só remanejo de caixa. Este briefing adiciona detecção automática, separação visual e exclusão dessas linhas do fluxo de conciliação normal.

---

## 1. Contexto e regras de negócio

- **Transferência interna** = movimento OFX onde o destinatário/remetente é uma empresa do grupo OU um sócio cadastrado. O dinheiro **muda de banco**, mas não muda de mão.
- **Impacto contábil correto**: a transferência **AFETA o saldo bancário de cada banco** (o banco real foi movimentado), mas **NÃO afeta o saldo ERP de resultado** (receita/despesa). Em consolidação, ambos os bancos compensam.
- **Não pode virar conta a pagar/receber** — seria duplicar registro. Por isso essas linhas devem sair do modal de revisão de débitos/créditos órfãos.
- **Detecção tem que ser conservadora**: melhor errar pra menos (não detectar) do que pra mais (marcar um pagamento legítimo a um cliente como transferência interna). Idealmente, exige confirmação por **par cruzado** (saída no banco A casa com entrada no banco B do mesmo grupo).

## 2. Princípios de segurança

1. **Aditivo, não destrutivo**: criar coluna nova `COM_NATUREZA` com default `'NORMAL'`. Movimentos antigos continuam tratados como sempre.
2. **Reversível**: usuário pode marcar/desmarcar manualmente. Detecção automática é só sugestão.
3. **Auditoria**: cada par de transferência fica registrado com data de detecção, usuário, e par cruzado.
4. **Não tocar em**: `saldoBancarioOfx` (transferência continua somando/subtraindo do saldo bancário de cada banco — está correto). `saldoErpConta` (já lê das contas a pagar/receber, naturalmente ignora movimentos OFX puros). BI/Dashboard (verificar se há leitura direta).
5. **Auto-populate inicial**: copiar CNPJs já cadastrados em `tb_empresa` pra `tb_grupo_documento` automaticamente — sem ação manual no primeiro acesso.

## 3. Plano de edição

### Fase A — Migration

Arquivo: `migrations/2026_06_09_transferencias_internas.sql`:

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

-- 2) Coluna nova em movimentos OFX para classificar natureza
ALTER TABLE tb_conciliacao_ofx_movimento
    ADD COLUMN COM_NATUREZA ENUM('NORMAL','TRANSFERENCIA_INTERNA','RENDIMENTO','TARIFA') NOT NULL DEFAULT 'NORMAL' AFTER COM_TIPO,
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

> **Atenção:** `REGEXP_REPLACE` exige MySQL 8.0+. Se for 5.7, substituir por `REPLACE(REPLACE(REPLACE(EMP_CNPJ,'.',''),'/',''),'-','')`.

### Fase B — Backend: CRUD do grupo de documentos

**Arquivo novo:** `app/endpoints/grupo_documentos.php` (ou adicionar actions em `endpoints/empresas.php` se preferir centralizar).

Actions:

```php
if ($acao === 'listar') {
    $st = $pdo->query("SELECT GDO_CODIGO_PK, GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_STATUS, GDO_OBSERVACAO,
                              DATE_FORMAT(GDO_DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data_cadastro_br
                       FROM tb_grupo_documento
                       ORDER BY GDO_TIPO, GDO_NOME");
    json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($acao === 'salvar') {
    $id   = (int)($_POST['id'] ?? 0);
    $tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
    $doc  = preg_replace('/\D+/', '', (string)($_POST['documento'] ?? ''));
    $nome = trim((string)($_POST['nome'] ?? ''));
    $obs  = trim((string)($_POST['observacao'] ?? ''));

    if (!in_array($tipo, ['PJ','PF'], true)) json_out(['ok'=>false,'msg'=>'Tipo inválido'], 422);
    if (!in_array(strlen($doc), [11, 14], true)) json_out(['ok'=>false,'msg'=>'Documento inválido'], 422);
    if ($nome === '') json_out(['ok'=>false,'msg'=>'Nome obrigatório'], 422);

    $usuario = (string)($_SESSION['user_nome'] ?? 'Sistema');

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
```

**Tela:** `app/grupo_documentos.php` (visual simples — tabela com modal de cadastro, igual outras telas do projeto). Pode esperar uma rodada de polimento; o essencial é o backend funcionar.

### Fase C — Backend: detecção de natureza durante o import

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, action `importar_ofx` (~linhas 1030–1088).

Adicionar **antes** do `foreach ($movimentos as $bloco)`:

```php
// Pré-carrega documentos do grupo (CNPJ/CPF próprios)
$stGrupo = $pdo->query("SELECT GDO_DOCUMENTO FROM tb_grupo_documento WHERE GDO_STATUS = 'ATIVO'");
$docsGrupo = array_flip($stGrupo->fetchAll(PDO::FETCH_COLUMN));
```

Substituir o bloco que define `$tipo` (linhas 1066–1072) por:

```php
if ($valor >= 0) {
    $entradas += $valor;
    $tipo = 'CREDITO';
} else {
    $saidas += abs($valor);
    $tipo = 'DEBITO';
}

// Detecta natureza pelo MEMO: CNPJ/CPF do grupo, rendimento, tarifa.
$natureza = 'NORMAL';
$docContraparte = null;

if (preg_match('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2})/', $descricao, $mDoc)) {
    $docLimpo = preg_replace('/\D+/', '', $mDoc[1]);
    $docContraparte = $docLimpo;
    if (isset($docsGrupo[$docLimpo])) {
        $natureza = 'TRANSFERENCIA_INTERNA';
    }
}

// Heurísticas adicionais por palavras-chave (conservadoras)
if ($natureza === 'NORMAL') {
    if (preg_match('/REND(IMENTO)?\s+PAGO\s+APLIC|APLIC\s+AUT/iu', $descricao)) {
        $natureza = 'RENDIMENTO';
    } elseif (preg_match('/TARIFA|IOF|TX\s+ANUIDADE/iu', $descricao)) {
        $natureza = 'TARIFA';
    }
}
```

Atualizar o INSERT (linhas 1001–1028):

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

E ajustar o `$stMov->execute([...])` pra incluir `:natureza` e `:doc_contraparte`.

### Fase D — Backend: match cruzado entre bancos (consolidação dos pares)

Nova action `detectar_pares_transferencia`, chamada automaticamente ao final do `importar_ofx` (e disponível manualmente):

```php
if ($acao === 'detectar_pares_transferencia') {
    $pdo->beginTransaction();
    try {
        // Procura saídas (DEBITO + TRANSFERENCIA_INTERNA) sem par
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

E **chamar essa lógica automaticamente** ao final do `importar_ofx` — adicionar antes do `$pdo->commit()` que fecha a importação, OU disparar via fetch no front após import bem-sucedido. Recomendo a segunda (mais explícito).

### Fase E — Backend: excluir transferências dos modais de revisão

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, actions `debitos_orfaos` (~linha 1280) e `creditos_orfaos` (~linha 1576).

Na query principal de cada uma, adicionar filtro:

```sql
AND m.COM_NATUREZA <> 'TRANSFERENCIA_INTERNA'
```

Assim, transferências internas detectadas **não aparecem** mais como "sem vínculo" e não geram sugestão de criar conta a pagar/receber.

Idem em `lancamentos_disponiveis`, `buscar_lancamento_existente` e qualquer outra ação que mostre movimentos OFX órfãos.

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

// Listar transferências detectadas SEM par (suspeitas sem confirmação cruzada)
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

// Marcar/desmarcar movimento como transferência interna (override manual)
if ($acao === 'alterar_natureza_movimento') {
    $movId    = (int)($_POST['movimento_fk'] ?? 0);
    $natureza = strtoupper((string)($_POST['natureza'] ?? 'NORMAL'));
    if (!in_array($natureza, ['NORMAL','TRANSFERENCIA_INTERNA','RENDIMENTO','TARIFA'], true)) {
        json_out(['ok'=>false,'msg'=>'Natureza inválida'], 422);
    }
    $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento SET COM_NATUREZA = ? WHERE COM_CODIGO_PK = ?")
        ->execute([$natureza, $movId]);

    // Se mudou pra NORMAL, cancelar par eventual
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

    // Valida que ambos são transferências internas e dos sinais corretos
    $stV = $pdo->prepare("SELECT COM_CODIGO_PK, COM_TIPO, COM_VALOR, COM_NATUREZA
                          FROM tb_conciliacao_ofx_movimento
                          WHERE COM_CODIGO_PK IN (?, ?)");
    $stV->execute([$origemId, $destinoId]);
    $rows = $stV->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2) json_out(['ok'=>false,'msg'=>'Movimentos não encontrados'], 404);

    $origem = $rows[0]['COM_CODIGO_PK'] == $origemId ? $rows[0] : $rows[1];
    $destino = $rows[0]['COM_CODIGO_PK'] == $destinoId ? $rows[0] : $rows[1];

    if ($origem['COM_TIPO'] !== 'DEBITO' || $destino['COM_TIPO'] !== 'CREDITO') {
        json_out(['ok'=>false,'msg'=>'Origem deve ser débito, destino deve ser crédito'], 422);
    }
    if (abs(abs((float)$origem['COM_VALOR']) - (float)$destino['COM_VALOR']) > 0.01) {
        json_out(['ok'=>false,'msg'=>'Valores não batem'], 422);
    }

    // Garante natureza TRANSFERENCIA_INTERNA nos dois
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

### Fase G — Frontend: aba "Transferências internas" na Conciliação Bancária

**Arquivo:** `app/conciliacao_bancaria.php`.

Adicionar botão no topo (perto de "Revisar vínculos OFX", "Conferir vínculos"):

```html
<button type="button" class="btn btn-sm btn-outline-info" id="btnTransferenciasInternas">
    <i class="bi bi-arrow-left-right me-1"></i>Transferências internas
</button>
```

E modal:

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
                        <p class="small text-muted">Movimentos suspeitos de transferência (CNPJ do grupo no MEMO) que ainda não casaram com um par no outro banco.</p>
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

E JS (simplificado — handlers para carregar cada aba via fetch das 4 actions da Fase F).

### Fase H — Frontend: badge no extrato espelho

No template de cada linha do extrato (procurar onde tem `COM_STATUS` "Conciliado"/"Importado"), adicionar:

```js
${r.natureza === 'TRANSFERENCIA_INTERNA' ? '<span class="badge bg-info-subtle text-info ms-1">Transf. interna</span>' : ''}
${r.natureza === 'RENDIMENTO' ? '<span class="badge bg-success-subtle text-success ms-1">Rendimento</span>' : ''}
${r.natureza === 'TARIFA' ? '<span class="badge bg-secondary-subtle text-secondary ms-1">Tarifa</span>' : ''}
```

E garantir que a action `listar_extrato` (ou equivalente) retorna o campo `COM_NATUREZA AS natureza` no SELECT.

### Fase I — BI/Dashboard: verificar leitura direta de OFX

Grep em `app/endpoints/bi.php` e `app/index.php` por `tb_conciliacao_ofx_movimento`. Se houver SUM ou COUNT direto desses movimentos pra calcular receita/despesa, adicionar `AND COM_NATUREZA <> 'TRANSFERENCIA_INTERNA'`.

Pelo que conhecemos do projeto, BI e Dashboard leem só de `tb_contas_pagar`/`tb_contas_receber`, então essa fase provavelmente é só validação.

---

## 4. Critérios de aceite

1. `php -l` em todos os arquivos alterados.
2. **Migration aplicada**: tabelas `tb_grupo_documento` e `tb_transferencia_interna` existem. Coluna `COM_NATUREZA` existe em `tb_conciliacao_ofx_movimento`.
3. **Auto-populate**: após rodar migration, `tb_grupo_documento` já contém CNPJs das empresas cadastradas em `tb_empresa`.
4. **Detecção no import**: importar OFX com PIX cujo MEMO tenha CNPJ de empresa do grupo → movimento entra com `COM_NATUREZA = 'TRANSFERENCIA_INTERNA'`. Importar PIX a fornecedor genérico → entra com `COM_NATUREZA = 'NORMAL'`.
5. **Detecção de rendimento e tarifa**: importar OFX com MEMO contendo "REND PAGO APLIC AUT" → marcado como `'RENDIMENTO'`. "TARIFA" no MEMO → `'TARIFA'`.
6. **Match cruzado**: importar OFX do BTG com débito R$ 1.000 pra AVS no dia 10/06, e OFX do Itaú com crédito R$ 1.000 da ASSESCCONT no dia 10/06 → ao rodar `detectar_pares_transferencia`, 1 par criado em `tb_transferencia_interna`. Aba "Pares casados" mostra o par.
7. **Exclusão dos modais de revisão**: movimentos `TRANSFERENCIA_INTERNA` **não aparecem** no modal "Débitos do extrato sem lançamento" nem "Créditos do extrato sem lançamento". Não tem opção de virar conta a pagar/receber.
8. **Badge visual no extrato**: linhas TRANSFERENCIA_INTERNA aparecem com badge "Transf. interna" azul. Linhas RENDIMENTO e TARIFA também.
9. **Override manual**: usuário pode marcar movimento normal como transferência (action `alterar_natureza_movimento`), ou desmarcar uma sugestão automática.
10. **Vinculação manual de par**: na aba "Sem par", usuário escolhe um débito e um crédito de bancos diferentes e cria par manualmente.
11. **Saldo bancário**: transferência interna **continua afetando** `saldoBancarioOfx` (banco real foi movimentado). Conferir que saldos de cada banco refletem entradas/saídas mesmo que sejam internas.
12. **Saldo ERP**: **não afetado** por transferências internas. Verificar que `saldoErpConta` não muda (ele só lê de contas a pagar/receber).
13. **Regressão**: import OFX antigo (sem CNPJ do grupo no MEMO) continua funcionando — todos movimentos entram como `'NORMAL'`.

## 5. O que NÃO mexer

- `saldoErpConta`, `saldoBancarioOfx` — não tocar.
- `criar_pagar_em_lote`, `criar_receber_em_lote`, `vincular_lancamento_existente`, `vincular_lancamentos_em_lote`, `cancelar_vinculo`, `cancelar_integracao` — todas preservadas.
- Modais existentes de débitos/créditos órfãos — só adicionar o filtro `<> 'TRANSFERENCIA_INTERNA'`, não mexer em outra coisa.
- Briefings anteriores e suas regras de match/vínculo — fora do escopo.

## 6. Arquivos afetados

| Arquivo | Mudança |
|---|---|
| `migrations/2026_06_09_transferencias_internas.sql` | Criar |
| `app/endpoints/conciliacao_bancaria.php` | Detecção no import + 4 actions novas (detectar_pares, listar_transferencias_internas, listar_transferencias_sem_par, alterar_natureza_movimento, vincular_par_manual) + filtro `<> 'TRANSFERENCIA_INTERNA'` em `debitos_orfaos`/`creditos_orfaos` |
| `app/endpoints/grupo_documentos.php` | Criar (CRUD) |
| `app/conciliacao_bancaria.php` | Botão + modal "Transferências internas" + badge no extrato |
| `app/grupo_documentos.php` | Criar (tela de cadastro) |
| `app/endpoints/bi.php` | Validar (provavelmente nenhuma mudança) |

Total: ~700 linhas adicionadas. Risco baixo — todas aditivas, com fallback claro (default `'NORMAL'` mantém comportamento atual).

## 7. Plano de rollback

1. `DROP TABLE tb_transferencia_interna;`
2. `DROP TABLE tb_grupo_documento;`
3. `ALTER TABLE tb_conciliacao_ofx_movimento DROP COLUMN COM_NATUREZA, DROP COLUMN COM_DOCUMENTO_CONTRAPARTE;`
4. `git revert` dos arquivos.

Sem perda de dados de receita/despesa — `tb_contas_pagar`/`tb_contas_receber` não são tocados.

## 8. Aviso pro cliente após aplicar

> "Agora o sistema reconhece automaticamente transferências entre suas próprias contas. Quando você faz um PIX da AVS (Itaú) pra ASSESCCONT (BTG), por exemplo, o sistema identifica como transferência interna e **não pede pra criar conta a receber/pagar** — fica registrado só como remanejo de caixa. Aparece com etiqueta azul 'Transf. interna' no extrato.
>
> A detecção usa o cadastro de CNPJs e CPFs do grupo (acessível em **Conciliação → Transferências internas → CNPJs do grupo**). Já populamos automaticamente com os CNPJs das empresas cadastradas. Adicione manualmente CPFs de sócios (Diogo, etc.) se quiser que os PIX entre eles e a empresa também sejam tratados como transferência interna.
>
> Rendimentos de aplicação e tarifas bancárias também são identificados automaticamente."

# Adendo ao Briefing 15 — Suporte ao formato OFX do Banco do Brasil

> O briefing 15 (transferências internas) foi escrito olhando o padrão **Itaú/BTG**, que joga toda a info no `<MEMO>`. O **Banco do Brasil** usa um formato diferente que vai fazer o parser atual e a detecção falhar. Este adendo cobre as diferenças. **Aplicar JUNTO com o briefing 15** (ou logo depois — não funciona standalone).

---

## 1. Diferenças críticas BB vs Itaú/BTG no OFX

Olhando o extrato real do BB que o cliente forneceu, identifiquei 5 problemas com o parser/detecção atuais:

### Diferença 1 — BB usa tag `<NAME>` para categoria e `<MEMO>` só pra detalhes

**OFX BB:**
```xml
<STMTTRN>
  <TRNTYPE>CREDIT</TRNTYPE>
  <DTPOSTED>20260601000000[-3:BRT]</DTPOSTED>
  <TRNAMT>23481.33</TRNAMT>
  <FITID>11.848.430.597.722</FITID>
  <NAME>Pix - Recebido</NAME>
  <MEMO>01/06 18:48 62432028000155 AVS APOIO A</MEMO>
</STMTTRN>
```

**OFX Itaú (comparação):**
```xml
<STMTTRN>
  <TRNTYPE>CREDIT</TRNTYPE>
  <DTPOSTED>20260511100000[-03:EST]</DTPOSTED>
  <TRNAMT>2849.56</TRNAMT>
  <FITID>20260511010</FITID>
  <MEMO>BOLETOS RECEBIDOS  09/05S</MEMO>
</STMTTRN>
```

O parser atual (linha 1033) só lê `<MEMO>` — **ignora `<NAME>`**. No BB, isso significa perder a categoria do movimento.

### Diferença 2 — `<MEMO>` vazio em vários movimentos

No BB, vários movimentos vêm com `<MEMO></MEMO>` (vazio). Exemplos:

```xml
<NAME>Cobrança</NAME>
<MEMO></MEMO>
```

```xml
<NAME>Saldo do dia</NAME>
<MEMO></MEMO>
```

O parser atual grava esses movimentos com descrição "Movimento OFX" genérica (fallback). Resultado: extrato fica ilegível.

### Diferença 3 — Linhas de "Saldo do dia" entram como movimento

O parser atual filtra linhas de saldo (linhas 1046–1049):
```php
if (preg_match('/^SALDO\s+(ANTERIOR|TOTAL|EM\s+CONTA|DISPON[IÍ]VEL|DO\s+DIA|FINAL|INICIAL)/u', $descNorm)
    || $descNorm === 'SALDO') {
    continue;
}
```

Mas filtra olhando **só o MEMO**. No BB, "Saldo do dia" está no `<NAME>` e o MEMO é vazio. **As linhas de saldo serão importadas como movimentos R$ 0,00**, poluindo o extrato com várias linhas inúteis (uma por dia).

### Diferença 4 — CNPJ no MEMO vem **sem formatação**

No BB, o CNPJ aparece como **só dígitos**: `62432028000155` (14 dígitos contínuos).

A regex atual (briefing 15 fase C):
```php
preg_match('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2})/', $descricao, $mDoc)
```

**Só aceita formato formatado** (`XX.XXX.XXX/XXXX-XX`). Não vai pegar `62432028000155`. Detecção de transferência interna por documento falha 100% no BB.

### Diferença 5 — BB tem aplicações automáticas (Rende Fácil)

```xml
<NAME>BB Rende Fácil</NAME>
<MEMO>Rende Facil</MEMO>
<TRNAMT>-23980.24</TRNAMT>   <!-- aplicação automática -->

<NAME>BB Rende Fácil</NAME>
<MEMO>Rende Facil</MEMO>
<TRNAMT>23996.30</TRNAMT>    <!-- resgate (sai com rendimento embutido) -->
```

São **movimentos entre conta corrente e aplicação automática do BB no mesmo banco**. Não é receita/despesa, mas também não é transferência interna entre bancos do grupo. Hoje o sistema vai tratar como saída/entrada normal — pediria pra criar conta a pagar/receber. Errado.

Além disso, o MEMO pode trazer só o nome do destinatário **sem CPF**:

```xml
<NAME>Pix - Enviado</NAME>
<MEMO>03/06 09:22 DIOGO ANDRE DA SILVA</MEMO>
```

PIX para o Diogo. Diogo é sócio (CPF cadastrado em `tb_grupo_documento`), mas no MEMO **não tem o CPF** — só o nome. Detecção por documento ainda assim falha. Precisa **detecção por nome**.

---

## 2. Princípios de segurança

1. **Não quebrar Itaú/BTG**. Todas as mudanças devem ser aditivas: ler `<NAME>` quando existir, fallback pro comportamento antigo quando não.
2. **CNPJ sem formatação não pode pegar dígitos aleatórios** (ex: confundir FITID com CNPJ). Validar com word boundaries `\b` e checar contra base do grupo.
3. **Detecção por nome é heurística** — só aceitar quando o NAME do OFX indicar PIX/TED/DOC (movimentos pessoa-a-pessoa) E houver substring match com nome cadastrado.
4. **Aplicações internas do mesmo banco** entram como `APLICACAO` (categoria nova) — não confundir com `TRANSFERENCIA_INTERNA` que é entre **bancos diferentes**.

---

## 3. Plano de ajuste

### Ajuste A.1 — Migration: nova categoria `APLICACAO` em COM_NATUREZA

**Arquivo:** `migrations/2026_06_09_transferencias_internas.sql` (briefing 15) — **antes de rodar**, ajustar a definição do ENUM:

```sql
ALTER TABLE tb_conciliacao_ofx_movimento
    ADD COLUMN COM_NATUREZA ENUM(
        'NORMAL',
        'TRANSFERENCIA_INTERNA',
        'APLICACAO',           -- NOVO: aplicação/resgate dentro do mesmo banco (Rende Fácil, etc.)
        'RENDIMENTO',
        'TARIFA'
    ) NOT NULL DEFAULT 'NORMAL' AFTER COM_TIPO,
    ADD COLUMN COM_DOCUMENTO_CONTRAPARTE VARCHAR(20) NULL AFTER COM_NATUREZA,
    ADD INDEX idx_com_natureza (COM_NATUREZA);
```

Se a migration do briefing 15 já rodou:

```sql
ALTER TABLE tb_conciliacao_ofx_movimento
    MODIFY COLUMN COM_NATUREZA ENUM('NORMAL','TRANSFERENCIA_INTERNA','APLICACAO','RENDIMENTO','TARIFA') NOT NULL DEFAULT 'NORMAL';
```

### Ajuste C.1 — Parser lê `<NAME>` e usa como descrição quando MEMO vazio

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, action `importar_ofx` (~linha 1030).

**Antes:**

```php
foreach ($movimentos as $bloco) {
    preg_match('/<DTPOSTED>([^\r\n<]+)/i', $bloco, $mData);
    preg_match('/<TRNAMT>([^\r\n<]+)/i', $bloco, $mValor);
    preg_match('/<MEMO>([^\r\n<]+)/i', $bloco, $mMemo);
    preg_match('/<FITID>([^\r\n<]+)/i', $bloco, $mFitid);

    $dataBruta = trim((string)($mData[1] ?? ''));
    $valor = (float)trim((string)($mValor[1] ?? 0));
    $descricao = trim((string)($mMemo[1] ?? 'Movimento OFX'));
    $documento = trim((string)($mFitid[1] ?? ''));
```

**Depois:**

```php
foreach ($movimentos as $bloco) {
    preg_match('/<DTPOSTED>([^\r\n<]+)/i', $bloco, $mData);
    preg_match('/<TRNAMT>([^\r\n<]+)/i', $bloco, $mValor);
    preg_match('/<MEMO>([^<]*)/i', $bloco, $mMemo);
    preg_match('/<NAME>([^<]*)/i', $bloco, $mName);
    preg_match('/<FITID>([^\r\n<]+)/i', $bloco, $mFitid);
    preg_match('/<TRNTYPE>([^\r\n<]+)/i', $bloco, $mTipoOfx);

    $dataBruta = trim((string)($mData[1] ?? ''));
    $valor = (float)trim((string)($mValor[1] ?? 0));
    $memoCru = trim((string)($mMemo[1] ?? ''));
    $nameCru = trim((string)($mName[1] ?? ''));
    $trnType = strtoupper(trim((string)($mTipoOfx[1] ?? '')));
    $documento = trim((string)($mFitid[1] ?? ''));

    // Descrição final combina NAME e MEMO. Banco do Brasil usa NAME pra categoria;
    // Itaú/BTG jogam tudo no MEMO. Combinando funciona pros dois.
    if ($nameCru !== '' && $memoCru !== '') {
        $descricao = $nameCru . ' · ' . $memoCru;
    } elseif ($nameCru !== '') {
        $descricao = $nameCru;
    } elseif ($memoCru !== '') {
        $descricao = $memoCru;
    } else {
        $descricao = 'Movimento OFX';
    }
```

> **Atenção:** mudei o regex de MEMO de `[^\r\n<]+` para `[^<]*` pra aceitar MEMO vazio (`<MEMO></MEMO>`). Idem pra NAME. O `+` virou `*` (zero ou mais caracteres).

### Ajuste C.2 — Filtro de linhas de saldo olha NAME também

**Antes (linhas ~1046–1049):**

```php
$descNorm = mb_strtoupper($descricao, 'UTF-8');
if (preg_match('/^SALDO\s+(ANTERIOR|TOTAL|EM\s+CONTA|DISPON[IÍ]VEL|DO\s+DIA|FINAL|INICIAL)/u', $descNorm)
    || $descNorm === 'SALDO') {
    continue;
}
```

**Depois:**

```php
$descNorm = mb_strtoupper($descricao, 'UTF-8');
$nameNorm = mb_strtoupper($nameCru, 'UTF-8');

// Filtra linhas de saldo: olha NAME (BB) e descrição completa (Itaú)
$padraoSaldo = '/^SALDO\s+(ANTERIOR|TOTAL|EM\s+CONTA|DISPON[IÍ]VEL|DO\s+DIA|FINAL|INICIAL)/u';
if (preg_match($padraoSaldo, $descNorm)
    || preg_match($padraoSaldo, $nameNorm)
    || $descNorm === 'SALDO'
    || $nameNorm === 'SALDO ANTERIOR'
    || $nameNorm === 'SALDO DO DIA') {
    continue;
}

// Também ignora movimentos com valor zero E categoria de saldo
if (abs($valor) < 0.005 && (str_contains($nameNorm, 'SALDO') || str_contains($descNorm, 'SALDO'))) {
    continue;
}
```

### Ajuste C.3 — Regex de CNPJ/CPF aceita formato sem pontuação

**Substituir** a regex (briefing 15 Fase C) por uma versão que aceita ambos formatos:

```php
$cnpjCpfPattern = '/(?:'
    . '\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}'   // CNPJ formatado
    . '|\d{3}\.\d{3}\.\d{3}-\d{2}'         // CPF formatado
    . '|(?<![\d.])\d{14}(?![\d.])'         // CNPJ só dígitos (14)
    . '|(?<![\d.])\d{11}(?![\d.])'         // CPF só dígitos (11)
    . ')/';

if (preg_match($cnpjCpfPattern, $descricao, $mDoc)) {
    $docLimpo = preg_replace('/\D+/', '', $mDoc[0]);
    if (in_array(strlen($docLimpo), [11, 14], true)) {
        $docContraparte = $docLimpo;
        if (isset($docsGrupo[$docLimpo])) {
            $natureza = 'TRANSFERENCIA_INTERNA';
        }
    }
}
```

> **Sobre `(?<![\d.])` e `(?![\d.])`:** são lookbehind/lookahead negativos que garantem que os 14 dígitos não estão grudados em mais dígitos ou pontos (evita pegar parte de outro número). Importante porque FITIDs do BB são longos (`11.848.430.597.722`) — sem essa proteção, a regex acharia "11848430597722" e tentaria tratar como CNPJ.

### Ajuste C.4 — Detecção por nome (heurística adicional)

**Adicionar** após a detecção por documento. Só dispara em movimentos PIX/TED/DOC (não em boletos/tarifas):

```php
// Detecção complementar por NOME quando documento não veio no MEMO.
// Só dispara em movimentos do tipo PIX/TED/DOC (transferências pessoa-a-pessoa).
$ehTransferencia = $nameCru !== '' && preg_match('/PIX|TED|DOC|TRANSFER/iu', $nameCru);

if ($natureza === 'NORMAL' && $ehTransferencia) {
    // Carrega cache de nomes do grupo (pré-carregado fora do loop — ver acima)
    foreach ($nomesGrupo as $nomeGrupo) {
        // Match: nome do grupo aparece no MEMO (case-insensitive)
        if (mb_stripos($descricao, $nomeGrupo) !== false) {
            $natureza = 'TRANSFERENCIA_INTERNA';
            break;
        }
    }
}
```

E pré-carregar a lista de nomes **antes do foreach** (junto com `$docsGrupo`):

```php
// Pré-carrega documentos e nomes do grupo
$stGrupo = $pdo->query("SELECT GDO_DOCUMENTO, GDO_NOME FROM tb_grupo_documento WHERE GDO_STATUS = 'ATIVO'");
$docsGrupo = [];
$nomesGrupo = [];
foreach ($stGrupo->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $docsGrupo[(string)$g['GDO_DOCUMENTO']] = true;
    $nome = trim(mb_strtoupper((string)$g['GDO_NOME'], 'UTF-8'));
    // Só considera nomes com 5+ caracteres pra evitar falsos positivos
    // (ex: nome "Bia" daria muito match falso)
    if (mb_strlen($nome) >= 5) {
        $nomesGrupo[] = $nome;
    }
}
```

### Ajuste C.5 — Categoria APLICACAO para aplicações internas

**Adicionar** na detecção de natureza, **antes** das heurísticas de RENDIMENTO e TARIFA:

```php
// Aplicação automática (BB Rende Fácil, Itaú Aplica Fácil, Bradesco MaxiInvest, etc.)
// Identifica entradas e saídas de aplicação automática da mesma conta — não é
// receita/despesa, é remanejamento interno entre saldo e aplicação.
if ($natureza === 'NORMAL') {
    $padraoAplicacao = '/RENDE\s+F[AÁ]CIL|APLIC(A[CÇ][AÃ]O)?\s+AUT|RESGATE\s+AUT|MAXI\s*INVEST|EASY\s*INVEST|FUNDO\s+AUTOM|CDB\s+AUT/iu';
    if (preg_match($padraoAplicacao, $nameCru) || preg_match($padraoAplicacao, $descricao)) {
        $natureza = 'APLICACAO';
    }
}

// Heurísticas existentes (rendimento, tarifa) — manter como estão
if ($natureza === 'NORMAL') {
    if (preg_match('/REND(IMENTO)?\s+PAGO\s+APLIC|REND(IMENTOS)?\s+POUPAN/iu', $descricao . ' ' . $nameCru)) {
        $natureza = 'RENDIMENTO';
    } elseif (preg_match('/TARIFA|D[EÉ]BITO\s+SERVI[CÇ]O|IOF|TX\s+ANUIDADE|TAR\.?\s+AGRUPADAS/iu', $descricao . ' ' . $nameCru)) {
        $natureza = 'TARIFA';
    }
}
```

### Ajuste E.1 — Filtro nos modais de revisão inclui APLICACAO

**Arquivo:** `app/endpoints/conciliacao_bancaria.php`, actions `debitos_orfaos`, `creditos_orfaos`, `lancamentos_disponiveis`, `buscar_lancamento_existente`.

**Trocar** o filtro `AND m.COM_NATUREZA <> 'TRANSFERENCIA_INTERNA'` por:

```sql
AND m.COM_NATUREZA NOT IN ('TRANSFERENCIA_INTERNA', 'APLICACAO', 'TARIFA', 'RENDIMENTO')
```

Justificativa: nenhuma dessas 4 naturezas é receita/despesa real — não devem virar conta a pagar/receber.

> **Decisão de design:** TARIFA e RENDIMENTO ainda podem virar conta de "Despesa financeira" ou "Receita financeira" se o cliente quiser registrar contabilmente. Mas como esses ainda não estão no fluxo, ficam fora do modal de revisão por enquanto. Se o cliente quiser depois, criamos uma aba "Receitas/despesas financeiras automáticas".

### Ajuste H.1 — Badge para APLICACAO no extrato

No template do extrato espelho, adicionar:

```js
${r.natureza === 'APLICACAO' ? '<span class="badge bg-warning-subtle text-warning-emphasis ms-1">Aplicação</span>' : ''}
```

---

## 4. Critérios de aceite específicos do BB

1. `php -l` continua OK.
2. **Importar OFX do BB** (`BB 01-06 a 07-06.ofx` fornecido pelo cliente):
   - 14 movimentos importados (descontadas as 4 linhas "Saldo do dia"/"Saldo Anterior" que devem ser ignoradas).
   - Nenhuma linha "Saldo do dia" aparece como movimento R$ 0,00.
3. **Descrições preservadas**:
   - Movimento PIX recebido R$ 23.481,33 (01/06) tem descrição `"Pix - Recebido · 01/06 18:48 62432028000155 AVS APOIO A"` (NAME + MEMO concatenados).
   - Movimento Cobrança R$ 500,90 (01/06) tem descrição `"Cobrança"` (NAME só, MEMO vazio).
4. **Detecção por CNPJ sem pontuação**: PIX R$ 23.481,33 com CNPJ `62432028000155` no MEMO → `COM_NATUREZA = 'TRANSFERENCIA_INTERNA'` (porque 62432028000155 é o CNPJ da AVS, do grupo).
5. **Detecção por nome**: PIX enviado R$ 22.500 pra `DIOGO ANDRE DA SILVA` (sem CPF) → `COM_NATUREZA = 'TRANSFERENCIA_INTERNA'` (porque "DIOGO ANDRE DA SILVA" deve estar cadastrado como sócio em `tb_grupo_documento`).
6. **Detecção APLICACAO**: as 3 linhas "BB Rende Fácil" (R$ -23.980,24 / R$ -16,02 / R$ 23.996,30) → `COM_NATUREZA = 'APLICACAO'`.
7. **Detecção TARIFA**: "Débito Serviço Cobrança" com MEMO "Tar. agrupadas" → `COM_NATUREZA = 'TARIFA'`.
8. **Exclusão de modais**: nenhum dos movimentos com natureza TRANSFERENCIA_INTERNA/APLICACAO/TARIFA/RENDIMENTO aparece no modal "Débitos/Créditos do extrato sem lançamento".
9. **Badge no extrato**: linhas APLICACAO aparecem com badge amarelo "Aplicação"; TRANSFERENCIA_INTERNA com badge azul "Transf. interna"; TARIFA com badge cinza "Tarifa".
10. **Regressão Itaú/BTG**: importar um OFX do Itaú depois do BB continua funcionando — as detecções antigas (com MEMO único) também passam.
11. **Falso positivo evitado**: importar um boleto pago pra um fornecedor genérico cujo nome **não** está no cadastro do grupo → `COM_NATUREZA = 'NORMAL'`. Não é classificado como transferência só porque o NAME diz "Boleto Pago".
12. **FITID longo não vira CNPJ**: o FITID `11.848.430.597.722` (com pontos) do BB não pode ser confundido com CNPJ pelo regex.

## 5. Cuidados na aplicação

1. **Aplicar JUNTO ou DEPOIS do briefing 15**. Standalone esse adendo não roda — depende das estruturas criadas lá.
2. **Cadastrar manualmente o nome dos sócios** em `tb_grupo_documento` (tipo PF) com nome completo. Sem isso, a detecção por nome do BB não funciona.
3. **Se a migration do briefing 15 já rodou** sem o ENUM expandido, rodar o `ALTER TABLE ... MODIFY COLUMN` da seção A.1.
4. **Documentar pro cliente** que o sistema agora reconhece padrões específicos do BB (Cobrança, Rende Fácil, etc.) e que pode adicionar nomes de mais pessoas/empresas do grupo no cadastro.

## 6. Arquivos afetados

| Arquivo | Mudança em relação ao briefing 15 |
|---|---|
| `migrations/2026_06_09_transferencias_internas.sql` | Adicionar `'APLICACAO'` ao ENUM de `COM_NATUREZA` |
| `app/endpoints/conciliacao_bancaria.php` | Parser ajustado (Ajuste C.1), filtro de saldo (C.2), regex CNPJ ampliada (C.3), detecção por nome (C.4), categoria APLICACAO (C.5), filtro de modais ampliado (E.1) |
| `app/conciliacao_bancaria.php` | Badge "Aplicação" no extrato (H.1) |

Total adicional ao briefing 15: ~60 linhas. Risco baixíssimo — todas adições defensivas.

## 7. Aviso pro cliente

> "O sistema agora reconhece também o formato do Banco do Brasil (e outros bancos que usem padrão parecido com tag NAME). Os movimentos do BB aparecem com descrição completa (categoria + detalhes) e as aplicações automáticas (Rende Fácil, MaxiInvest, etc.) são identificadas separadamente como 'Aplicação' — não pedem virar conta a pagar/receber, ficam só como movimentação interna entre saldo e fundo."

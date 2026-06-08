<?php
// /app/bancos.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Bancos / Cobrança</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem
        }

        .badge-soft-danger {
            background: rgba(239, 68, 68, .12);
            color: #991b1b;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem
        }

        .table thead th {
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17, 24, 39, .08) !important
        }

        .help {
            font-size: .86rem;
            color: #64748b
        }

        .help-mini {
            font-size: .84rem;
            color: #64748b
        }

        .modal-xxl {
            max-width: 92vw;
        }

        @media (min-width: 1200px) {
            .modal-xxl {
                max-width: 1100px;
            }
        }

        .nav-tabs .nav-link {
            border-radius: 12px 12px 0 0;
            font-weight: 600;
        }

        .tab-pane {
            padding-top: 14px;
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        /* ------ Cards de saldo ------ */
        .banco-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px 18px 14px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 190px;
            transition: box-shadow .15s, transform .15s, border-color .15s;
        }
        .banco-card:hover {
            box-shadow: 0 6px 22px rgba(15, 23, 42, 0.08);
            transform: translateY(-1px);
            border-color: #c7d2fe;
        }
        .banco-card .bc-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .banco-card .bc-apelido {
            font-weight: 700;
            font-size: 1rem;
            color: #0f172a;
            line-height: 1.2;
            display: flex;
            align-items: center;
            gap: .55rem;
        }
        .banco-card .bc-codbanco {
            font-size: .75rem;
            color: #64748b;
            font-weight: 600;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px 8px;
            white-space: nowrap;
        }
        .banco-card .bc-conta {
            color: #475569;
            font-size: .82rem;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .banco-card .bc-saldo-lbl {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .banco-card .bc-saldo-val {
            font-size: 1.65rem;
            font-weight: 700;
            line-height: 1.05;
            font-variant-numeric: tabular-nums;
            color: #16a34a;
        }
        .banco-card .bc-saldo-val.neg {
            color: #dc2626;
        }
        .banco-card .bc-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
        }
        .banco-card .bc-actions .btn {
            flex: 1;
            font-size: .78rem;
            border-radius: 8px;
            padding: .42rem .6rem;
        }
        .bc-total-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #fff;
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.10);
        }
        .bc-total-strip .lbl {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            opacity: .85;
        }
        .bc-total-strip .val {
            font-size: 1.6rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        @media (max-width: 575.98px) {
            .banco-card { min-height: auto; }
            .banco-card .bc-saldo-val { font-size: 1.4rem; }
            .bc-total-strip .val { font-size: 1.25rem; }
        }
    </style>
</head>

<body data-page="config">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Bancos / Cobrança</span>

                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted">
                            <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?>
                            (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)
                        </span>
                        <a class="btn btn-sm btn-outline-danger" href="logout.php">
                            <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
                        </a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid py-4">

                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
                    <div>
                        <h5 class="mb-1 mt-1">Bancos e Cobrança</h5>
                        <p class="help mb-0">
                            Saldo atual de cada banco e transferências entre contas. Ao confirmar uma transferência,
                            saldos são atualizados automaticamente em Fluxo de Caixa, BI e Conciliação.
                        </p>
                    </div>
                    <div class="mt-2 mt-sm-0 d-flex gap-2">
                        <button id="btnAtualizarSaldos" type="button" class="btn btn-sm btn-outline-secondary" title="Recarregar saldos">
                            <i class="fa-solid fa-rotate me-1"></i>Atualizar
                        </button>
                        <button id="btnNovoBanco" type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalBanco">
                            <i class="fa-solid fa-plus me-1"></i>Novo cadastro
                        </button>
                    </div>
                </div>

                <!-- Faixa de saldo total -->
                <div class="bc-total-strip" id="bcTotalStrip">
                    <div>
                        <div class="lbl"><i class="fa-solid fa-coins me-1"></i>Saldo total nas contas ativas</div>
                        <div class="val" id="bcTotalVal">R$ 0,00</div>
                    </div>
                    <div class="text-end">
                        <div class="lbl">Consulta</div>
                        <div class="small" id="bcConsultaEm">—</div>
                    </div>
                </div>

                <!-- Grade de cards de bancos com saldo -->
                <div id="bancosCards" class="row g-3 mb-4">
                    <div class="col-12 text-muted small">Carregando bancos…</div>
                </div>

                <!-- Filtros -->
                <div class="card mb-3">
                    <div class="card-body py-3">
                        <form class="row g-2 align-items-end" id="frmFiltros">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="text" class="form-control form-control-sm" id="fBuscar"
                                    placeholder="Apelido, banco, convênio, carteira, agência/conta, cedente..." />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Situação</label>
                                <select class="form-select form-select-sm" id="fStatus">
                                    <option value="">Todas</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>
                            <div class="col-md-2 text-end">
                                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Cadastros de cobrança</span>
                            <span class="small text-muted" id="lblTotal">—</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Apelido</th>
                                        <th>Banco</th>
                                        <th>Convênio</th>
                                        <th>Carteira</th>
                                        <th>Agência/Conta</th>
                                        <th>Cedente</th>
                                        <th>Ambiente</th>
                                        <th>Situação</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbBancos">
                                    <tr>
                                        <td colspan="10" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

                <footer class="text-muted small mt-4">
                    © <?= date('Y') ?> DRE - Sistema Financeiro
                </footer>

            </div>
        </div>
    </div>

    <!-- Modal Transferência entre Bancos -->
    <div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="fa-solid fa-arrow-right-arrow-left me-2"></i>Transferência entre Bancos
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <form id="frmTransferencia" autocomplete="off">
                        <input type="hidden" id="trbOrigemId" value="">

                        <div class="alert alert-light border py-2 small mb-3">
                            <i class="fa-solid fa-info-circle me-1"></i>
                            A transferência debita o saldo do banco origem e credita no destino. Os ajustes aparecem
                            no extrato bancário e atualizam o saldo ERP em todas as telas.
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Banco de origem</label>
                            <div class="form-control bg-white" id="trbOrigemLabel" style="height:auto;padding:.5rem .75rem">—</div>
                            <div class="help-mini mt-1">Saldo atual: <strong id="trbSaldoOrigem">R$ 0,00</strong></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="trbDestino">Banco de destino *</label>
                            <select class="form-select" id="trbDestino" required>
                                <option value="">— Selecione —</option>
                            </select>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold" for="trbValor">Valor *</label>
                                <div class="input-group">
                                    <span class="input-group-text">R$</span>
                                    <input type="text" class="form-control text-end mono" id="trbValor" placeholder="0,00" required>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold" for="trbData">Data *</label>
                                <input type="date" class="form-control" id="trbData" required>
                                <div class="help-mini mt-1" id="trbValorAlerta"></div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label small fw-semibold" for="trbDescricao">Descrição (opcional)</label>
                            <input type="text" class="form-control" id="trbDescricao" placeholder="Ex.: pró-labore, reembolso, ajuste mensal">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-sm btn-success" id="btnConfirmarTransferencia">
                        <i class="fa-solid fa-check me-1"></i>Confirmar transferência
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Criar/Editar -->
    <div class="modal fade" id="modalBanco" tabindex="-1" aria-labelledby="modalBancoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalBancoLabel">Cadastro de Cobrança</h5>
                        <div class="help-mini">Preencha os dados do convênio/cedente e parâmetros CNAB para boletos e remessa.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <form id="frmBanco" autocomplete="off">
                        <input type="hidden" id="BAN_ID" name="BAN_ID" value="">

                        <!-- Tabs -->
                        <ul class="nav nav-tabs" role="tablist" id="tabsBanco">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basico" type="button" role="tab">
                                    <i class="fa-solid fa-building-columns me-1"></i>Dados do banco
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-conta" type="button" role="tab">
                                    <i class="fa-solid fa-wallet me-1"></i>Conta / Cedente
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cobranca" type="button" role="tab">
                                    <i class="fa-solid fa-file-invoice-dollar me-1"></i>Cobrança (Carteira/Convênio)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cnab" type="button" role="tab">
                                    <i class="fa-solid fa-file-code me-1"></i>CNAB / Remessa
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">

                            <!-- ABA 1: BASICO -->
                            <div class="tab-pane fade show active" id="tab-basico" role="tabpanel">
                                <div class="row g-3">

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Apelido *</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_APELIDO" name="BAN_APELIDO"
                                            placeholder="Ex: Bradesco Matriz / Sicredi Filial" required>
                                        <div class="help-mini mt-1">Nome interno para você identificar rápido na seleção.</div>
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Situação</label>
                                        <select class="form-select form-select-sm" id="BAN_STATUS" name="BAN_STATUS">
                                            <option value="ATIVO">Ativo</option>
                                            <option value="INATIVO">Inativo</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Banco (cód. COMPE) *</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CODIGO" name="BAN_CODIGO"
                                            placeholder="Ex: 237" maxlength="3" required>
                                        <div class="help-mini mt-1">3 dígitos. Ex: 237, 341, 748...</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Nome do banco *</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_NOME" name="BAN_NOME" placeholder="Ex: Bradesco" required>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">ISPB</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_ISPB" name="BAN_ISPB" placeholder="Opcional (8 dígitos)">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Ambiente</label>
                                        <select class="form-select form-select-sm" id="BAN_AMBIENTE" name="BAN_AMBIENTE">
                                            <option value="PRODUCAO">Produção</option>
                                            <option value="HOMOLOGACAO">Homologação</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Site</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_SITE" name="BAN_SITE" placeholder="https://">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Observação</label>
                                        <textarea class="form-control form-control-sm" id="BAN_OBSERVACAO" name="BAN_OBSERVACAO" rows="3"></textarea>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check form-switch mt-1">
                                            <input class="form-check-input" type="checkbox" id="BAN_CAIXA_INTERNO" name="BAN_CAIXA_INTERNO">
                                            <label class="form-check-label fw-semibold small" for="BAN_CAIXA_INTERNO">
                                                <i class="bi bi-safe me-1"></i>Banco de recebimentos internos da empresa
                                            </label>
                                            <div class="form-text">Quando marcado, não será necessário preencher as demais abas (Conta/Cedente, Cobrança, CNAB).</div>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <!-- ABA 2: CONTA / CEDENTE -->
                            <div class="tab-pane fade" id="tab-conta" role="tabpanel">
                                <div class="row g-3">

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Cedente (Nome) *</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_NOME" required>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Cedente (Documento) *</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_DOC" placeholder="CNPJ/CPF" required>
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Tipo doc.</label>
                                        <select class="form-select form-select-sm" id="BAN_CEDENTE_TIPO_DOC">
                                            <option value="CNPJ">CNPJ</option>
                                            <option value="CPF">CPF</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Código cedente / Beneficiário</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CODIGO_CEDENTE" placeholder="Opcional">
                                        <div class="help-mini mt-1">Alguns bancos exigem (beneficiário/cedente).</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Agência *</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_AGENCIA" required>
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Dígito agência</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_AGENCIA_DV" maxlength="1">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Conta *</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CONTA" required>
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Dígito conta</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CONTA_DV" maxlength="2">
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Operação</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_OPERACAO" placeholder="Opcional">
                                    </div>

                                    <div class="col-12">
                                        <hr class="my-2">
                                        <div class="small text-muted mb-1">Endereço do cedente (CEP com auto completar)</div>
                                    </div>

                                    <!-- CEP PRIMEIRO + AUTOCOMPLETE -->
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">CEP</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_CEP" placeholder="00000-000">
                                    </div>

                                    <div class="col-12 col-md-7">
                                        <label class="form-label small">Logradouro</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_LOGRADOURO" placeholder="Rua / Av...">
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Número</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_NUMERO" placeholder="Nº">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Complemento</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_COMPLEMENTO" placeholder="Apto, sala...">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Bairro</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_CEDENTE_BAIRRO" placeholder="Bairro">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">UF</label>
                                        <select class="form-select form-select-sm" id="BAN_CEDENTE_UF">
                                            <option value="">Selecione</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Cidade</label>
                                        <select class="form-select form-select-sm" id="BAN_CEDENTE_CIDADE">
                                            <option value="">Selecione o estado</option>
                                        </select>
                                    </div>


                                    <!-- mantido por compatibilidade (vai receber o endereço “completo”) -->
                                    <input type="hidden" id="BAN_CEDENTE_ENDERECO" value="">

                                </div>
                            </div>

                            <!-- ABA 3: COBRANÇA -->
                            <div class="tab-pane fade" id="tab-cobranca" role="tabpanel">
                                <div class="row g-3">

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Convênio *</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CONVENIO" required>
                                        <div class="help-mini mt-1">Exigido na maioria dos bancos (cobrança registrada).</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Carteira *</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CARTEIRA" required>
                                        <div class="help-mini mt-1">Ex: 09 / 101 / 1 / 17...</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Modalidade / Variação carteira</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_MODALIDADE" placeholder="Opcional">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Cedente (cód. no banco)</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_CEDENTE_COD_BANCO" placeholder="Opcional">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Nosso Número - tamanho</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_NOSSO_NUM_TAM" min="1" max="20" value="11">
                                        <div class="help-mini mt-1">Usado na formatação do boleto (varia por banco).</div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Nosso Número - próximo</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_NOSSO_NUM_PROX" min="1" step="1" value="1">
                                        <div class="help-mini mt-1">Sequencial interno. No futuro, ao gerar boleto, incrementa.</div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Aceite</label>
                                        <select class="form-select form-select-sm" id="BAN_ACEITE">
                                            <option value="N">Não</option>
                                            <option value="S">Sim</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Espécie doc.</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_ESPECIE_DOC" placeholder="Ex: DM" value="DM">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Espécie moeda</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_ESPECIE_MOEDA" placeholder="Ex: 9" value="9">
                                        <div class="help-mini mt-1">Geralmente 9 = Real.</div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Protesto (dias)</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_PROTESTO_DIAS" min="0" step="1" value="0">
                                        <div class="help-mini mt-1">0 = não protestar.</div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Baixa/Devolução (dias)</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_BAIXA_DIAS" min="0" step="1" value="0">
                                        <div class="help-mini mt-1">0 = não baixar automaticamente.</div>
                                    </div>

                                    <div class="col-12 col-md-8">
                                        <label class="form-label small">Instruções (boleto) - opcional</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_INSTRUCOES" placeholder="Ex: Após vencimento cobrar multa de ...">
                                    </div>

                                </div>
                            </div>

                            <!-- ABA 4: CNAB -->
                            <div class="tab-pane fade" id="tab-cnab" role="tabpanel">
                                <div class="row g-3">

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">CNAB</label>
                                        <select class="form-select form-select-sm" id="BAN_CNAB">
                                            <option value="CNAB240">CNAB 240</option>
                                            <option value="CNAB400">CNAB 400</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Layout / Padrão</label>
                                        <input type="text" class="form-control form-control-sm" id="BAN_LAYOUT" placeholder="Ex: Bradesco CNAB400 Cobrança">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Código empresa no banco</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_COD_EMPRESA_BANCO" placeholder="Opcional">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Código transmissão</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_COD_TRANSMISSAO" placeholder="Opcional">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Seq. remessa (próximo)</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_REMESSA_SEQ_PROX" min="1" step="1" value="1">
                                        <div class="help-mini mt-1">No futuro incrementa ao gerar arquivo.</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Seq. lote (próximo)</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_LOTE_SEQ_PROX" min="1" step="1" value="1">
                                        <div class="help-mini mt-1">CNAB240 usa lote. CNAB400 pode ignorar.</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Seq. registro (próximo)</label>
                                        <input type="number" class="form-control form-control-sm" id="BAN_REG_SEQ_PROX" min="1" step="1" value="1">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Arquivo (prefixo)</label>
                                        <input type="text" class="form-control form-control-sm mono" id="BAN_ARQ_PREFIXO" placeholder="Ex: REM">
                                    </div>

                                    <div class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <div class="fw-semibold mb-1">Dica</div>
                                            Esses campos deixam sua estrutura pronta pra, no futuro, gerar remessa/retorno sem re-trabalho.
                                            Quando formos implementar CNAB, a gente valida o layout real do banco escolhido.
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div><!-- /tab-content -->
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarBanco">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/bancos.php';

        async function api(params, method = 'GET') {
            let url = ENDPOINT;
            const opt = {
                method
            };

            if (method === 'GET') {
                url += '?' + new URLSearchParams(params).toString();
            } else {
                const fd = new FormData();
                Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
                opt.body = fd;
            }

            const r = await fetch(url, opt);
            const txt = await r.text();

            let j;
            try {
                j = JSON.parse(txt);
            } catch {
                console.error('NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON.');
            }

            if (!j.ok) throw new Error(j.msg || 'Erro na requisição');
            return j;
        }

        const modalBanco = new bootstrap.Modal(document.getElementById('modalBanco'));

        function badgeStatus(s) {
            if (s === 'ATIVO') return '<span class="badge-soft-success">ATIVO</span>';
            return '<span class="badge-soft-danger">INATIVO</span>';
        }

        const safe = (v) => (v ?? '').toString();

        function onlyDigits(v) {
            return (v || '').toString().replace(/\D/g, '');
        }

        function maskCEP(v) {
            const d = onlyDigits(v).slice(0, 8);
            if (d.length <= 5) return d;
            return d.slice(0, 5) + '-' + d.slice(5);
        }

        function montarEnderecoCedenteHidden() {
            const log = document.getElementById('BAN_CEDENTE_LOGRADOURO').value.trim();
            const num = document.getElementById('BAN_CEDENTE_NUMERO').value.trim();
            const bai = document.getElementById('BAN_CEDENTE_BAIRRO').value.trim();
            const comp = document.getElementById('BAN_CEDENTE_COMPLEMENTO').value.trim();

            let s = log;
            if (num) s += (s ? ', ' : '') + num;
            if (bai) s += (s ? ' - ' : '') + bai;
            if (comp) s += (s ? ' - ' : '') + comp;

            document.getElementById('BAN_CEDENTE_ENDERECO').value = s;
        }

        /* ============================
           UF / CIDADES (SELECT)
           ============================ */

        const UFS = [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
            'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
            'SP', 'SE', 'TO'
        ];

        function carregarUFs() {
            const ufSel = document.getElementById('BAN_CEDENTE_UF');
            if (!ufSel) return;
            ufSel.innerHTML = '<option value="">Selecione</option>';
            UFS.forEach(uf => {
                ufSel.insertAdjacentHTML('beforeend', `<option value="${uf}">${uf}</option>`);
            });
        }

        async function carregarCidades(uf, cidadeSelecionada = '') {
            const cidSel = document.getElementById('BAN_CEDENTE_CIDADE');
            if (!cidSel) return;

            cidSel.innerHTML = '<option value="">Carregando...</option>';

            if (!uf) {
                cidSel.innerHTML = '<option value="">Selecione o estado</option>';
                return;
            }

            try {
                const r = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios`, {
                    cache: 'no-store'
                });
                const cidades = await r.json();

                cidSel.innerHTML = '<option value="">Selecione</option>';
                cidades.forEach(c => {
                    const sel = (cidadeSelecionada && c.nome === cidadeSelecionada) ? 'selected' : '';
                    cidSel.insertAdjacentHTML('beforeend', `<option value="${c.nome}" ${sel}>${c.nome}</option>`);
                });

                // se veio cidadeSelecionada mas não bateu por acento/variação, tenta setar pelo value direto
                if (cidadeSelecionada) {
                    cidSel.value = cidadeSelecionada;
                }
            } catch (e) {
                cidSel.innerHTML = '<option value="">Erro ao carregar cidades</option>';
            }
        }

        /* ============================
           CEP (ViaCEP) + UF/Cidade
           ============================ */

        async function buscarCepCedente() {
            const cep = onlyDigits(document.getElementById('BAN_CEDENTE_CEP').value);
            if (cep.length !== 8) return;

            try {
                const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`, {
                    cache: 'no-store'
                });
                const j = await r.json();
                if (j.erro) throw new Error('CEP não encontrado.');

                document.getElementById('BAN_CEDENTE_LOGRADOURO').value = j.logradouro || '';
                document.getElementById('BAN_CEDENTE_BAIRRO').value = j.bairro || '';

                // UF é select: seta e carrega cidades
                const uf = (j.uf || '').toUpperCase();
                document.getElementById('BAN_CEDENTE_UF').value = uf;

                // carrega cidades e já seleciona a cidade do ViaCEP
                await carregarCidades(uf, j.localidade || '');

                montarEnderecoCedenteHidden();
                document.getElementById('BAN_CEDENTE_NUMERO').focus();
            } catch (e) {
                Swal.fire({
                    icon: 'warning',
                    title: 'CEP',
                    text: e.message
                });
            }
        }

        function limparForm() {
            document.getElementById('frmBanco').reset();
            document.getElementById('BAN_ID').value = '';
            document.getElementById('BAN_STATUS').value = 'ATIVO';
            document.getElementById('BAN_AMBIENTE').value = 'PRODUCAO';
            document.getElementById('BAN_CNAB').value = 'CNAB240';

            // defaults sequenciais
            document.getElementById('BAN_NOSSO_NUM_TAM').value = 11;
            document.getElementById('BAN_NOSSO_NUM_PROX').value = 1;
            document.getElementById('BAN_REMESSA_SEQ_PROX').value = 1;
            document.getElementById('BAN_LOTE_SEQ_PROX').value = 1;
            document.getElementById('BAN_REG_SEQ_PROX').value = 1;

            // defaults cobrança
            document.getElementById('BAN_ACEITE').value = 'N';
            document.getElementById('BAN_ESPECIE_DOC').value = 'DM';
            document.getElementById('BAN_ESPECIE_MOEDA').value = '9';
            document.getElementById('BAN_PROTESTO_DIAS').value = 0;
            document.getElementById('BAN_BAIXA_DIAS').value = 0;

            // limpa endereço novo + hidden
            if (document.getElementById('BAN_CEDENTE_LOGRADOURO')) document.getElementById('BAN_CEDENTE_LOGRADOURO').value = '';
            if (document.getElementById('BAN_CEDENTE_NUMERO')) document.getElementById('BAN_CEDENTE_NUMERO').value = '';
            if (document.getElementById('BAN_CEDENTE_COMPLEMENTO')) document.getElementById('BAN_CEDENTE_COMPLEMENTO').value = '';
            if (document.getElementById('BAN_CEDENTE_BAIRRO')) document.getElementById('BAN_CEDENTE_BAIRRO').value = '';
            if (document.getElementById('BAN_CEDENTE_ENDERECO')) document.getElementById('BAN_CEDENTE_ENDERECO').value = '';

            // reseta checkbox caixa interno
            document.getElementById('BAN_CAIXA_INTERNO').checked = false;

            // reseta selects UF/Cidade
            const ufSel = document.getElementById('BAN_CEDENTE_UF');
            const cidSel = document.getElementById('BAN_CEDENTE_CIDADE');
            if (ufSel) ufSel.value = '';
            if (cidSel) cidSel.innerHTML = '<option value="">Selecione o estado</option>';

            // volta primeira aba
            const firstTab = document.querySelector('#tabsBanco button.nav-link.active') || document.querySelector('#tabsBanco button.nav-link');
            if (firstTab) new bootstrap.Tab(firstTab).show();
        }

        function getForm() {
            montarEnderecoCedenteHidden();

            return {
                BAN_ID: (document.getElementById('BAN_ID').value || '').trim(),

                // básico
                BAN_APELIDO: document.getElementById('BAN_APELIDO').value.trim(),
                BAN_STATUS: document.getElementById('BAN_STATUS').value,
                BAN_CODIGO: onlyDigits(document.getElementById('BAN_CODIGO').value).slice(0, 3),
                BAN_NOME: document.getElementById('BAN_NOME').value.trim(),
                BAN_ISPB: onlyDigits(document.getElementById('BAN_ISPB').value).slice(0, 8),
                BAN_AMBIENTE: document.getElementById('BAN_AMBIENTE').value,
                BAN_SITE: document.getElementById('BAN_SITE').value.trim(),
                BAN_OBSERVACAO: document.getElementById('BAN_OBSERVACAO').value.trim(),

                // cedente/conta
                BAN_CEDENTE_NOME: document.getElementById('BAN_CEDENTE_NOME').value.trim(),
                BAN_CEDENTE_DOC: document.getElementById('BAN_CEDENTE_DOC').value.trim(),
                BAN_CEDENTE_TIPO_DOC: document.getElementById('BAN_CEDENTE_TIPO_DOC').value,
                BAN_CODIGO_CEDENTE: document.getElementById('BAN_CODIGO_CEDENTE').value.trim(),
                BAN_AGENCIA: document.getElementById('BAN_AGENCIA').value.trim(),
                BAN_AGENCIA_DV: document.getElementById('BAN_AGENCIA_DV').value.trim(),
                BAN_CONTA: document.getElementById('BAN_CONTA').value.trim(),
                BAN_CONTA_DV: document.getElementById('BAN_CONTA_DV').value.trim(),
                BAN_OPERACAO: document.getElementById('BAN_OPERACAO').value.trim(),

                // endereço (novo)
                BAN_CEDENTE_CEP: document.getElementById('BAN_CEDENTE_CEP').value.trim(),
                BAN_CEDENTE_LOGRADOURO: (document.getElementById('BAN_CEDENTE_LOGRADOURO')?.value || '').trim(),
                BAN_CEDENTE_NUMERO: (document.getElementById('BAN_CEDENTE_NUMERO')?.value || '').trim(),
                BAN_CEDENTE_COMPLEMENTO: (document.getElementById('BAN_CEDENTE_COMPLEMENTO')?.value || '').trim(),
                BAN_CEDENTE_BAIRRO: (document.getElementById('BAN_CEDENTE_BAIRRO')?.value || '').trim(),
                BAN_CEDENTE_CIDADE: (document.getElementById('BAN_CEDENTE_CIDADE')?.value || '').trim(),
                BAN_CEDENTE_UF: (document.getElementById('BAN_CEDENTE_UF')?.value || '').trim().toUpperCase(),

                // compat antigo (hidden)
                BAN_CEDENTE_ENDERECO: document.getElementById('BAN_CEDENTE_ENDERECO').value.trim(),

                // cobrança
                BAN_CONVENIO: document.getElementById('BAN_CONVENIO').value.trim(),
                BAN_CARTEIRA: document.getElementById('BAN_CARTEIRA').value.trim(),
                BAN_MODALIDADE: document.getElementById('BAN_MODALIDADE').value.trim(),
                BAN_CEDENTE_COD_BANCO: document.getElementById('BAN_CEDENTE_COD_BANCO').value.trim(),
                BAN_NOSSO_NUM_TAM: document.getElementById('BAN_NOSSO_NUM_TAM').value,
                BAN_NOSSO_NUM_PROX: document.getElementById('BAN_NOSSO_NUM_PROX').value,
                BAN_ACEITE: document.getElementById('BAN_ACEITE').value,
                BAN_ESPECIE_DOC: document.getElementById('BAN_ESPECIE_DOC').value.trim(),
                BAN_ESPECIE_MOEDA: document.getElementById('BAN_ESPECIE_MOEDA').value.trim(),
                BAN_PROTESTO_DIAS: document.getElementById('BAN_PROTESTO_DIAS').value,
                BAN_BAIXA_DIAS: document.getElementById('BAN_BAIXA_DIAS').value,
                BAN_INSTRUCOES: document.getElementById('BAN_INSTRUCOES').value.trim(),

                // cnab
                BAN_CNAB: document.getElementById('BAN_CNAB').value,
                BAN_LAYOUT: document.getElementById('BAN_LAYOUT').value.trim(),
                BAN_COD_EMPRESA_BANCO: document.getElementById('BAN_COD_EMPRESA_BANCO').value.trim(),
                BAN_COD_TRANSMISSAO: document.getElementById('BAN_COD_TRANSMISSAO').value.trim(),
                BAN_REMESSA_SEQ_PROX: document.getElementById('BAN_REMESSA_SEQ_PROX').value,
                BAN_LOTE_SEQ_PROX: document.getElementById('BAN_LOTE_SEQ_PROX').value,
                BAN_REG_SEQ_PROX: document.getElementById('BAN_REG_SEQ_PROX').value,
                BAN_ARQ_PREFIXO: document.getElementById('BAN_ARQ_PREFIXO').value.trim(),
            };
        }

        // 🔥 IMPORTANTE: setForm precisa ser async pra aguardar carregarCidades antes de setar cidade
        async function setForm(u) {
            document.getElementById('BAN_ID').value = u.BAN_ID || '';

            // básico
            document.getElementById('BAN_APELIDO').value = u.BAN_APELIDO || '';
            document.getElementById('BAN_STATUS').value = u.BAN_STATUS || 'ATIVO';
            document.getElementById('BAN_CODIGO').value = u.BAN_CODIGO || '';
            document.getElementById('BAN_NOME').value = u.BAN_NOME || '';
            document.getElementById('BAN_ISPB').value = u.BAN_ISPB || '';
            document.getElementById('BAN_AMBIENTE').value = u.BAN_AMBIENTE || 'PRODUCAO';
            document.getElementById('BAN_SITE').value = u.BAN_SITE || '';
            document.getElementById('BAN_OBSERVACAO').value = u.BAN_OBSERVACAO || '';
            document.getElementById('BAN_CAIXA_INTERNO').checked = (u.BAN_CAIXA_INTERNO == 1 || u.BAN_CAIXA_INTERNO === '1');

            // cedente/conta
            document.getElementById('BAN_CEDENTE_NOME').value = u.BAN_CEDENTE_NOME || '';
            document.getElementById('BAN_CEDENTE_DOC').value = u.BAN_CEDENTE_DOC || '';
            document.getElementById('BAN_CEDENTE_TIPO_DOC').value = u.BAN_CEDENTE_TIPO_DOC || 'CNPJ';
            document.getElementById('BAN_CODIGO_CEDENTE').value = u.BAN_CODIGO_CEDENTE || '';
            document.getElementById('BAN_AGENCIA').value = u.BAN_AGENCIA || '';
            document.getElementById('BAN_AGENCIA_DV').value = u.BAN_AGENCIA_DV || '';
            document.getElementById('BAN_CONTA').value = u.BAN_CONTA || '';
            document.getElementById('BAN_CONTA_DV').value = u.BAN_CONTA_DV || '';
            document.getElementById('BAN_OPERACAO').value = u.BAN_OPERACAO || '';

            // endereço novo
            document.getElementById('BAN_CEDENTE_CEP').value = u.BAN_CEDENTE_CEP || '';
            document.getElementById('BAN_CEDENTE_LOGRADOURO').value = u.BAN_CEDENTE_LOGRADOURO || '';
            document.getElementById('BAN_CEDENTE_NUMERO').value = u.BAN_CEDENTE_NUMERO || '';
            document.getElementById('BAN_CEDENTE_COMPLEMENTO').value = u.BAN_CEDENTE_COMPLEMENTO || '';
            document.getElementById('BAN_CEDENTE_BAIRRO').value = u.BAN_CEDENTE_BAIRRO || '';

            // UF/Cidade (select): primeiro UF, depois carrega cidades e seleciona
            const uf = (u.BAN_CEDENTE_UF || '').toUpperCase();
            document.getElementById('BAN_CEDENTE_UF').value = uf;
            await carregarCidades(uf, u.BAN_CEDENTE_CIDADE || '');

            // compat antigo
            document.getElementById('BAN_CEDENTE_ENDERECO').value = u.BAN_CEDENTE_ENDERECO || '';

            // cobrança
            document.getElementById('BAN_CONVENIO').value = u.BAN_CONVENIO || '';
            document.getElementById('BAN_CARTEIRA').value = u.BAN_CARTEIRA || '';
            document.getElementById('BAN_MODALIDADE').value = u.BAN_MODALIDADE || '';
            document.getElementById('BAN_CEDENTE_COD_BANCO').value = u.BAN_CEDENTE_COD_BANCO || '';
            document.getElementById('BAN_NOSSO_NUM_TAM').value = u.BAN_NOSSO_NUM_TAM ?? 11;
            document.getElementById('BAN_NOSSO_NUM_PROX').value = u.BAN_NOSSO_NUM_PROX ?? 1;
            document.getElementById('BAN_ACEITE').value = u.BAN_ACEITE || 'N';
            document.getElementById('BAN_ESPECIE_DOC').value = u.BAN_ESPECIE_DOC || 'DM';
            document.getElementById('BAN_ESPECIE_MOEDA').value = u.BAN_ESPECIE_MOEDA || '9';
            document.getElementById('BAN_PROTESTO_DIAS').value = u.BAN_PROTESTO_DIAS ?? 0;
            document.getElementById('BAN_BAIXA_DIAS').value = u.BAN_BAIXA_DIAS ?? 0;
            document.getElementById('BAN_INSTRUCOES').value = u.BAN_INSTRUCOES || '';

            // cnab
            document.getElementById('BAN_CNAB').value = u.BAN_CNAB || 'CNAB240';
            document.getElementById('BAN_LAYOUT').value = u.BAN_LAYOUT || '';
            document.getElementById('BAN_COD_EMPRESA_BANCO').value = u.BAN_COD_EMPRESA_BANCO || '';
            document.getElementById('BAN_COD_TRANSMISSAO').value = u.BAN_COD_TRANSMISSAO || '';
            document.getElementById('BAN_REMESSA_SEQ_PROX').value = u.BAN_REMESSA_SEQ_PROX ?? 1;
            document.getElementById('BAN_LOTE_SEQ_PROX').value = u.BAN_LOTE_SEQ_PROX ?? 1;
            document.getElementById('BAN_REG_SEQ_PROX').value = u.BAN_REG_SEQ_PROX ?? 1;
            document.getElementById('BAN_ARQ_PREFIXO').value = u.BAN_ARQ_PREFIXO || '';

            montarEnderecoCedenteHidden();
        }

        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const status = document.getElementById('fStatus').value;

            const j = await api({
                acao: 'listar',
                buscar,
                status
            }, 'GET');

            const tb = document.getElementById('tbBancos');
            tb.innerHTML = '';
            document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="10" class="text-muted small">Nenhum cadastro encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const btnEdit = `<button class="btn btn-sm btn-outline-primary me-1" title="Editar" data-id="${r.BAN_ID}" data-act="editar"><i class="fa-solid fa-pen"></i></button>`;
                const btnStatus = (r.BAN_STATUS === 'ATIVO') ?
                    `<button class="btn btn-sm btn-outline-warning" title="Inativar" data-id="${r.BAN_ID}" data-act="inativar"><i class="fa-solid fa-ban"></i></button>` :
                    `<button class="btn btn-sm btn-outline-success" title="Reativar" data-id="${r.BAN_ID}" data-act="reativar"><i class="fa-solid fa-rotate"></i></button>`;

                const agenciaConta = `${safe(r.BAN_AGENCIA)}${r.BAN_AGENCIA_DV ? '-' + safe(r.BAN_AGENCIA_DV) : ''} / ${safe(r.BAN_CONTA)}${r.BAN_CONTA_DV ? '-' + safe(r.BAN_CONTA_DV) : ''}`;

                tb.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${i+1}</td>
                    <td>${safe(r.BAN_APELIDO)}</td>
                    <td>${safe(r.BAN_CODIGO)} - ${safe(r.BAN_NOME)}</td>
                    <td>${safe(r.BAN_CONVENIO)}</td>
                    <td>${safe(r.BAN_CARTEIRA)}</td>
                    <td>${agenciaConta}</td>
                    <td>${safe(r.BAN_CEDENTE_NOME)}</td>
                    <td>${safe(r.BAN_AMBIENTE || 'PRODUCAO')}</td>
                    <td>${badgeStatus(r.BAN_STATUS)}</td>
                    <td class="text-end">${btnEdit}${btnStatus}</td>
                </tr>
            `);
            });
        }

        async function abrirNovo() {
            limparForm();
            document.getElementById('modalBancoLabel').textContent = 'Novo cadastro de cobrança';
            modalBanco.show();
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            limparForm();
            await setForm(j.row);
            document.getElementById('modalBancoLabel').textContent = `Editar cobrança #${j.row.BAN_ID}`;
            modalBanco.show();
        }

        async function salvar() {
            const d = getForm();
            const caixaInterno = document.getElementById('BAN_CAIXA_INTERNO').checked;
            d.BAN_CAIXA_INTERNO = caixaInterno ? '1' : '0';

            if (!d.BAN_APELIDO || !d.BAN_CODIGO || d.BAN_CODIGO.length !== 3 || !d.BAN_NOME) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe Apelido, Banco (COMPE 3 dígitos) e Nome do banco.'
                });
                return;
            }
            // Se NÃO for caixa interno, validar demais abas
            if (!caixaInterno) {
                if (!d.BAN_CEDENTE_NOME || !d.BAN_CEDENTE_DOC || !d.BAN_AGENCIA || !d.BAN_CONTA) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe Cedente (nome/doc), Agência e Conta.'
                    });
                    return;
                }
                if (!d.BAN_CONVENIO || !d.BAN_CARTEIRA) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe Convênio e Carteira.'
                    });
                    return;
                }
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');
                modalBanco.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Salvo',
                    text: 'Cadastro salvo com sucesso!',
                    timer: 900,
                    showConfirmButton: false
                });
                await listar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        async function inativar(id) {
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Inativar cadastro?',
                text: 'Ele ficará INATIVO (não exclui).',
                showCancelButton: true,
                confirmButtonText: 'Sim, inativar',
                cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;

            await api({
                acao: 'inativar',
                id
            }, 'POST');
            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Inativado.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        async function reativar(id) {
            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar cadastro?',
                showCancelButton: true,
                confirmButtonText: 'Sim, reativar',
                cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;

            await api({
                acao: 'reativar',
                id
            }, 'POST');
            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Reativado.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        // binds
        document.getElementById('btnNovoBanco').addEventListener('click', abrirNovo);
        document.getElementById('btnSalvarBanco').addEventListener('click', salvar);

        document.getElementById('frmFiltros').addEventListener('submit', (e) => {
            e.preventDefault();
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbBancos').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            const id = btn.dataset.id;
            const act = btn.dataset.act;

            if (act === 'editar') abrirEditar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'inativar') inativar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'reativar') reativar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        // input only digits
        document.getElementById('BAN_CODIGO').addEventListener('input', (e) => e.target.value = onlyDigits(e.target.value).slice(0, 3));
        document.getElementById('BAN_ISPB').addEventListener('input', (e) => e.target.value = onlyDigits(e.target.value).slice(0, 8));
        document.getElementById('BAN_CEDENTE_DOC').addEventListener('input', (e) => e.target.value = e.target.value.replace(/[^\d\.\-\/]/g, ''));

        // UF é SELECT: usa CHANGE (não input)
        document.getElementById('BAN_CEDENTE_UF').addEventListener('change', async (e) => {
            await carregarCidades((e.target.value || '').toUpperCase(), '');
        });

        // CEP mask + busca
        document.getElementById('BAN_CEDENTE_CEP').addEventListener('input', (e) => e.target.value = maskCEP(e.target.value));
        document.getElementById('BAN_CEDENTE_CEP').addEventListener('blur', buscarCepCedente);
        document.getElementById('BAN_CEDENTE_CEP').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                buscarCepCedente();
            }
        });

        // manter hidden atualizado
        ['BAN_CEDENTE_LOGRADOURO', 'BAN_CEDENTE_NUMERO', 'BAN_CEDENTE_COMPLEMENTO', 'BAN_CEDENTE_BAIRRO'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', montarEnderecoCedenteHidden);
        });

        // =====================================================
        //   CARDS DE BANCOS COM SALDO + TRANSFERÊNCIA
        // =====================================================
        const ENDPOINT_TRB = 'endpoints/transferencia_bancaria.php';
        let BANCOS_CACHE = [];

        const fmtBRL = v => Number(v || 0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' });
        const fmtDataBR = s => { if (!s) return '—'; const d = new Date(s + 'T00:00:00'); return d.toLocaleDateString('pt-BR'); };
        const escHTML = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        async function carregarCardsBancos() {
            const grid = document.getElementById('bancosCards');
            grid.innerHTML = '<div class="col-12 text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i> Carregando saldos…</div>';
            try {
                const j = await api({ acao: 'listar_com_saldo' });
                BANCOS_CACHE = j.rows || [];
                document.getElementById('bcTotalVal').textContent = fmtBRL(j.total_saldo || 0);
                document.getElementById('bcConsultaEm').textContent = fmtDataBR(j.consulta_em || '');

                if (!BANCOS_CACHE.length) {
                    grid.innerHTML = '<div class="col-12 text-muted small">Nenhum banco ativo cadastrado.</div>';
                    return;
                }

                grid.innerHTML = BANCOS_CACHE.map(b => {
                    const ag = (b.BAN_AGENCIA || '') + (b.BAN_AGENCIA_DV ? '-' + b.BAN_AGENCIA_DV : '');
                    const cc = (b.BAN_CONTA   || '') + (b.BAN_CONTA_DV   ? '-' + b.BAN_CONTA_DV   : '');
                    const sal = Number(b.SALDO_ATUAL || 0);
                    const neg = sal < 0 ? 'neg' : '';
                    return `
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="banco-card">
                                <div class="bc-head">
                                    <div class="bc-apelido">
                                        <i class="fa-solid fa-building-columns text-primary"></i>
                                        ${escHTML(b.BAN_APELIDO || b.BAN_NOME || 'Banco #' + b.BAN_ID)}
                                    </div>
                                    <span class="bc-codbanco">${escHTML(b.BAN_CODIGO || '—')} — ${escHTML((b.BAN_NOME || '').split(' ')[0])}</span>
                                </div>
                                <div class="bc-conta">
                                    <span><i class="fa-solid fa-code-branch text-muted me-1"></i>Ag. ${escHTML(ag || '—')}</span>
                                    <span><i class="fa-solid fa-hashtag text-muted me-1"></i>Cc. ${escHTML(cc || '—')}</span>
                                </div>
                                <div>
                                    <div class="bc-saldo-lbl">Saldo atual (ERP)</div>
                                    <div class="bc-saldo-val ${neg}">${fmtBRL(sal)}</div>
                                </div>
                                <div class="bc-actions">
                                    <button type="button" class="btn btn-success" onclick="abrirTransferencia(${Number(b.BAN_ID)})">
                                        <i class="fa-solid fa-arrow-right-arrow-left me-1"></i>Transferir
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" onclick="editarBancoCard(${Number(b.BAN_ID)})" title="Editar cadastro">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                </div>
                            </div>
                        </div>`;
                }).join('');
            } catch (err) {
                grid.innerHTML = `<div class="col-12 text-danger small">Erro ao carregar: ${escHTML(err.message || err)}</div>`;
            }
        }

        window.editarBancoCard = function(id) {
            // Reaproveita fluxo existente de edição (mesma rotina do botão "Editar" da tabela)
            if (typeof editar === 'function') return editar(id);
            // Fallback: dispara modal já com id carregado via API
            api({ acao: 'get', id }).then(j => {
                if (typeof preencherForm === 'function' && j.row) preencherForm(j.row);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBanco')).show();
            });
        };

        window.abrirTransferencia = function(bancoOrigemId) {
            const origem = BANCOS_CACHE.find(b => Number(b.BAN_ID) === Number(bancoOrigemId));
            if (!origem) { Swal.fire({icon:'error', title:'Banco não encontrado'}); return; }

            document.getElementById('trbOrigemId').value = String(origem.BAN_ID);
            document.getElementById('trbOrigemLabel').innerHTML =
                `<strong>${escHTML(origem.BAN_APELIDO)}</strong>` +
                `<span class="text-muted small ms-2">${escHTML(origem.BAN_CODIGO || '')} ${escHTML(origem.BAN_NOME || '')}</span>`;
            document.getElementById('trbSaldoOrigem').textContent = fmtBRL(origem.SALDO_ATUAL || 0);

            // Destinos = todos os outros bancos ativos
            const selDest = document.getElementById('trbDestino');
            selDest.innerHTML = '<option value="">— Selecione —</option>' +
                BANCOS_CACHE
                    .filter(b => Number(b.BAN_ID) !== Number(origem.BAN_ID))
                    .map(b => `<option value="${b.BAN_ID}">${escHTML(b.BAN_APELIDO)} — ${escHTML(b.BAN_NOME || '')}</option>`)
                    .join('');

            document.getElementById('trbValor').value = '';
            document.getElementById('trbDescricao').value = '';
            document.getElementById('trbData').value = new Date().toISOString().slice(0, 10);
            document.getElementById('trbValorAlerta').textContent = '';

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTransferencia')).show();
        };

        // Máscara simples de moeda BR
        document.getElementById('trbValor')?.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '');
            if (!v) { this.value = ''; return; }
            v = (parseInt(v, 10) / 100).toFixed(2);
            this.value = v.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            // Alerta se saldo for negativo
            const valorNum = parseFloat(v);
            const saldo = Number(BANCOS_CACHE.find(b => Number(b.BAN_ID) === Number(document.getElementById('trbOrigemId').value))?.SALDO_ATUAL || 0);
            const alerta = document.getElementById('trbValorAlerta');
            if (valorNum > saldo) {
                alerta.innerHTML = `<span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Saldo ficará negativo: ${fmtBRL(saldo - valorNum)}</span>`;
            } else {
                alerta.textContent = '';
            }
        });

        document.getElementById('btnConfirmarTransferencia')?.addEventListener('click', async () => {
            const origemId  = parseInt(document.getElementById('trbOrigemId').value || '0', 10);
            const destinoId = parseInt(document.getElementById('trbDestino').value || '0', 10);
            const valorStr  = document.getElementById('trbValor').value.trim();
            const valor     = parseFloat(valorStr.replace(/\./g, '').replace(',', '.')) || 0;
            const data      = document.getElementById('trbData').value;
            const descricao = document.getElementById('trbDescricao').value.trim();

            if (!destinoId)        { Swal.fire({icon:'warning', title:'Selecione o banco de destino'}); return; }
            if (origemId===destinoId){ Swal.fire({icon:'warning', title:'Origem e destino devem ser diferentes'}); return; }
            if (valor <= 0)        { Swal.fire({icon:'warning', title:'Informe um valor positivo'}); return; }
            if (!data)             { Swal.fire({icon:'warning', title:'Informe a data'}); return; }

            const origem  = BANCOS_CACHE.find(b => Number(b.BAN_ID) === origemId);
            const destino = BANCOS_CACHE.find(b => Number(b.BAN_ID) === destinoId);

            const conf = await Swal.fire({
                title: 'Confirmar transferência?',
                html: `<div class="text-start small">
                    <div><strong>${escHTML(origem?.BAN_APELIDO || '')}</strong> ➜ <strong>${escHTML(destino?.BAN_APELIDO || '')}</strong></div>
                    <div class="mt-1">Valor: <strong>${fmtBRL(valor)}</strong></div>
                    <div>Data: <strong>${fmtDataBR(data)}</strong></div>
                    ${descricao ? '<div class="mt-1">Descrição: ' + escHTML(descricao) + '</div>' : ''}
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Confirmar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#16a34a',
            });
            if (!conf.isConfirmed) return;

            const btn = document.getElementById('btnConfirmarTransferencia');
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processando…';

            try {
                const fd = new FormData();
                fd.append('acao', 'salvar');
                fd.append('banco_origem_id',  String(origemId));
                fd.append('banco_destino_id', String(destinoId));
                fd.append('valor',            valor.toFixed(2));
                fd.append('data',             data);
                fd.append('descricao',        descricao);

                const r = await fetch(ENDPOINT_TRB, { method:'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) throw new Error(j.msg || 'Erro ao gravar transferência');

                bootstrap.Modal.getInstance(document.getElementById('modalTransferencia'))?.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Transferência registrada',
                    text: 'Os saldos foram atualizados.',
                    timer: 1800,
                    showConfirmButton: false
                });
                await carregarCardsBancos();
                if (typeof listar === 'function') listar();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Falha', text: err.message || String(err) });
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Confirmar transferência';
            }
        });

        document.getElementById('btnAtualizarSaldos')?.addEventListener('click', () => carregarCardsBancos());

        // init
        carregarUFs();
        // deixa o select de cidade “em espera” até selecionar UF
        const cidSelInit = document.getElementById('BAN_CEDENTE_CIDADE');
        if (cidSelInit) cidSelInit.innerHTML = '<option value="">Selecione o estado</option>';

        carregarCardsBancos();
        listar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        }));
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>
<div class="modal fade" id="modalAjusteSaldoConciliacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajustar saldo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Banco</label>
                        <select id="aj_banco_fk" class="form-select"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Conta</label>
                        <select id="aj_conta_fk" class="form-select"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Data</label>
                        <input type="date" id="aj_data" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Campo a ajustar</label>
                        <select id="aj_campo" class="form-select">
                            <option value="SALDO_BANCARIO">Saldo bancário</option>
                            <option value="SALDO_ERP">Saldo ERP</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Operação</label>
                        <select id="aj_operacao" class="form-select">
                            <option value="SOMA">Somar</option>
                            <option value="SUB">Subtrair</option>
                            <option value="SET">Definir exato</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Valor</label>
                        <input type="text" id="aj_valor" class="form-control valor-mask">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Motivo</label>
                        <select id="aj_motivo" class="form-select">
                            <option value="">Selecione</option>
                            <option value="CONCILIACAO">Conciliação bancária</option>
                            <option value="CORRECAO_LANCAMENTO">Correção de lançamento</option>
                            <option value="SALDO_INICIAL">Saldo inicial</option>
                            <option value="TARIFA">Tarifa não lançada</option>
                            <option value="OUTRO">Outro</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Saldo atual</label>
                        <input type="text" id="aj_saldo_atual" class="form-control" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observação</label>
                        <textarea id="aj_observacao" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" id="btnSalvarAjusteSaldo">Salvar ajuste</button>
            </div>
        </div>
    </div>
</div>
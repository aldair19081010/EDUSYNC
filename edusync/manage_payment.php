<?php
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$students = [];
$stmt = $conn->prepare("SELECT id,name,id_no,status FROM student WHERE school_id=? AND status IN('Activo','Retirado','Egresado') ORDER BY name");
$stmt->bind_param('i', $schoolId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $students[] = $row;
$stmt->close();
$methods = [];
$result = $conn->query('SELECT id,name FROM payment_methods ORDER BY name');
while ($result && ($row = $result->fetch_assoc())) $methods[] = $row;
$discountTable = $conn->query("SHOW TABLES LIKE 'debt_discounts'");
$discountReady = $discountTable && $discountTable->num_rows > 0;
$correctionOf = (int)($_GET['correction_of'] ?? 0);
$adminDiscountId = (int)($_GET['admin_discount_id'] ?? 0);
$correction = null;
if ($correctionOf > 0) {
    $column = $conn->query("SHOW COLUMNS FROM payment_operations LIKE 'corrected_from_id'");
    if ($column && $column->num_rows) {
        $stmt = $conn->prepare("SELECT po.id,po.student_id,po.payment_date,po.remarks,po.receipt_full,s.name student_name FROM payment_operations po INNER JOIN student s ON s.id=po.student_id WHERE po.id=? AND po.school_id=? AND po.status='Confirmado' LIMIT 1");
        $stmt->bind_param('ii', $correctionOf, $schoolId); $stmt->execute(); $base = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($base) {
            $base['concepts'] = []; $base['methods'] = [];
            $discountJoin = $discountReady ? "LEFT JOIN debt_discounts dd ON dd.payment_operation_id=p.operation_id AND dd.debt_id=p.ef_id AND dd.status='Aplicado'" : '';
            $discountFields = $discountReady ? ',dd.discount_type,dd.discount_value,dd.reason discount_reason' : ",NULL discount_type,NULL discount_value,NULL discount_reason";
            $stmt = $conn->prepare("SELECT p.ef_id,p.amount,c.course,ay.year $discountFields FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id LEFT JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id $discountJoin WHERE p.operation_id=? AND p.payment_status='Confirmado' ORDER BY COALESCE(ef.due_date,'9999-12-31'),COALESCE(ef.installment_number,9999),p.id");
            $stmt->bind_param('i', $correctionOf); $stmt->execute(); $result=$stmt->get_result(); while($row=$result->fetch_assoc())$base['concepts'][]=$row; $stmt->close();
            $stmt = $conn->prepare('SELECT payment_method_id method_id,amount,reference_number,bank_name,operation_date FROM payment_operation_methods WHERE operation_id=? ORDER BY id');
            $stmt->bind_param('i', $correctionOf); $stmt->execute(); $result=$stmt->get_result(); while($row=$result->fetch_assoc())$base['methods'][]=$row; $stmt->close();
            $correction = $base;
        }
    }
}
?>
<style>
#uni_modal .modal-dialog{max-width:1180px;width:calc(100% - 32px)}#uni_modal .modal-body{padding:1.25rem 1.5rem}.pm-section{border-bottom:1px solid #e8edf4;padding-bottom:14px;margin-bottom:16px}.pm-section h6{font-weight:700;color:#2f6fed;margin-bottom:12px}.pm-row{border:1px solid #e1e7ef;border-radius:9px;padding:11px;margin-bottom:9px;background:#fff}.pm-total{background:#f3f7ff;border-radius:10px;padding:12px;font-weight:700}.pm-discount{background:#f8fafc;border-radius:8px;padding:9px;margin-top:9px}.pm-discount-total{color:#079455}.pm-balance{font-size:.77rem;color:#667085}.pm-overpay{display:none;margin-top:6px;padding:6px 9px;border-radius:6px;background:#fff4e5;color:#9a5b00;font-size:.78rem;font-weight:600}.pm-overpay.show{display:block}@media(max-width:767px){#uni_modal .modal-dialog{width:calc(100% - 16px);margin:8px}.pm-row .form-group{margin-bottom:10px!important}}
</style>
<div class="container-fluid">
<form id="payment-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="selected_concepts" id="selected_concepts">
    <input type="hidden" name="payment_splits" id="payment_splits">
    <input type="hidden" name="correction_of" value="<?php echo $correction ? (int)$correction['id'] : 0; ?>">
    <input type="hidden" name="admin_discount_id" value="<?php echo $correction ? $adminDiscountId : 0; ?>">
    <?php if ($correction): ?><div class="alert alert-warning"><strong>Corrección del recibo <?php echo htmlspecialchars($correction['receipt_full']); ?>.</strong> Al guardar, el original se anulará y se generará un recibo nuevo.</div><div class="form-group"><label>Motivo de la corrección <span class="text-danger">*</span></label><input name="correction_reason" class="form-control" minlength="3" maxlength="255" required placeholder="Indique qué dato se está corrigiendo"></div><?php endif; ?>
    <?php if (!$discountReady): ?><div class="alert alert-info py-2"><i class="fa fa-info-circle mr-1"></i>Para aplicar descuentos durante el cobro, ejecuta <code>sql/payment_discounts_upgrade.sql</code>. El registro normal de pagos continúa disponible.</div><?php endif; ?>
    <div id="payment-msg"></div>
    <div class="pm-section"><h6><i class="fa fa-user mr-2"></i>Estudiante y fecha</h6><div class="form-row">
        <div class="form-group col-md-7"><label>Estudiante</label><select name="student_id" id="student_id" class="form-control select2" <?php echo $correction ? 'disabled' : ''; ?> required><option value="">Seleccione</option><?php foreach ($students as $student): ?><option value="<?php echo (int)$student['id']; ?>" <?php echo $correction && (int)$correction['student_id']===(int)$student['id']?'selected':''; ?>><?php echo htmlspecialchars($student['id_no'] . ' - ' . $student['name'] . ($student['status'] !== 'Activo' ? ' [' . $student['status'] . ']' : '')); ?></option><?php endforeach; ?></select><?php if($correction): ?><input type="hidden" name="student_id" value="<?php echo (int)$correction['student_id']; ?>"><?php endif; ?></div>
        <div class="form-group col-md-5"><label>Fecha y hora del pago</label><input type="datetime-local" name="payment_date" class="form-control" value="<?php echo htmlspecialchars($correction ? date('Y-m-d\TH:i',strtotime($correction['payment_date'])) : date('Y-m-d\TH:i')); ?>" required></div>
    </div></div>
    <div class="pm-section"><h6><i class="fa fa-file-invoice mr-2"></i>Aplicación del pago</h6>
        <div class="form-group"><label>Deudas pendientes</label><select id="debt-select" class="form-control select2" multiple disabled></select></div>
        <div id="debt-breakdown"></div>
        <div class="pm-total d-flex justify-content-between"><span>Total a recibir <small class="pm-discount-total ml-2" id="discount-total"></small></span><span id="payment-total">S/ 0.00</span></div>
    </div>
    <div class="pm-section"><h6><i class="fa fa-credit-card mr-2"></i>Medios de pago</h6><div id="method-rows"></div><button type="button" class="btn btn-sm btn-outline-primary" id="add-method"><i class="fa fa-plus mr-1"></i>Agregar medio</button></div>
    <div class="form-group"><label>Observaciones</label><textarea name="remarks" class="form-control" rows="2" maxlength="1000"><?php echo htmlspecialchars($correction['remarks'] ?? ''); ?></textarea></div>
    <div class="text-right"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button> <button class="btn btn-primary" id="save-payment"><i class="fa fa-save mr-1"></i>Registrar pago</button></div>
</form>
</div>
<script type="text/template" id="method-template"><div class="pm-row method-row"><div class="form-row align-items-end"><div class="form-group col-md-3 mb-0"><label>Medio</label><select class="form-control method-id"><option value="">Seleccione</option><?php foreach ($methods as $method): ?><option value="<?php echo (int)$method['id']; ?>"><?php echo htmlspecialchars($method['name']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3 mb-0"><label>Monto</label><input type="number" min="0.01" step="0.01" class="form-control method-amount"></div><div class="form-group col-md-2 mb-0"><label>N.º operación</label><input class="form-control method-reference" maxlength="100"></div><div class="form-group col-md-3 mb-0"><label>Banco o entidad</label><input class="form-control method-bank" maxlength="100"></div><div class="form-group col-md-1 mb-0"><button type="button" class="btn btn-outline-danger remove-method"><i class="fa fa-trash"></i></button></div></div></div></script>
<script>
(function($){
    const discountReady = <?php echo $discountReady ? 'true' : 'false'; ?>;
    const correction = <?php echo json_encode($correction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let debts = [];
    $('.select2').select2({width:'100%',dropdownParent:$('#uni_modal')});
    function money(value){return 'S/ ' + Number(value || 0).toFixed(2)}
    function paymentTotal(){let total=0;$('.debt-amount').each(function(){total+=Number(this.value||0)});return total}
    function discountFor(row){
        let balance=Number(row.data('balance')||0),type=row.find('.discount-type').val(),value=Number(row.find('.discount-value').val()||0);
        if(type==='final_amount') return value>0&&value<balance?Math.round((balance-value)*100)/100:0;
        if(type==='percentage') return Math.min(balance,Math.round(balance*Math.min(value,100))/100);
        if(type==='fixed') return Math.min(balance,value);
        return 0;
    }
    function syncTotals(){
        let total=paymentTotal(),discount=0;$('.debt-row').each(function(){discount+=discountFor($(this))});
        $('#payment-total').text(money(total));$('#discount-total').text(discount>0?'Descuentos: − '+money(discount):'');
        $('.debt-row').each(function(){let row=$(this),allowed=Math.max(0,Number(row.data('balance')||0)-discountFor(row)),amount=Number(row.find('.debt-amount').val()||0),excess=Math.max(0,amount-allowed);row.find('.pm-overpay').toggleClass('show',excess>.009).text(excess>.009?'Pago mayor al saldo: excede por '+money(excess)+'. Se registrará como caso especial.':'')});
        if($('.method-row').length===1)$('.method-amount').val(total.toFixed(2));
    }
    function recalculateDiscount(row){
        let balance=Number(row.data('balance')||0),discount=discountFor(row),type=row.find('.discount-type').val();
        row.find('.discount-fields').toggleClass('d-none',!type);
        row.find('.discount-preview').text(type?'Descuento: '+money(discount)+' · Saldo después del descuento: '+money(balance-discount):'');
        if(type)row.find('.debt-amount').val(Math.max(0,balance-discount).toFixed(2)).removeAttr('max');
        else row.find('.debt-amount').val(balance.toFixed(2)).removeAttr('max');
        syncTotals();
    }
    function addMethod(){let row=$($('#method-template').html());$('#method-rows').append(row);if($('.method-row').length===1)row.find('.method-amount').val(paymentTotal().toFixed(2))}
    if(!correction)addMethod();
    $('#add-method').click(addMethod);
    $(document).on('click','.remove-method',function(){if($('.method-row').length>1)$(this).closest('.method-row').remove();else alert_toast('Debe conservar al menos un medio.','warning')});
    $('#student_id').change(function(){
        let id=this.value;debts=[];$('#debt-breakdown').empty();$('#debt-select').prop('disabled',true).empty();syncTotals();if(!id)return;
        $.post('ajax.php?action=get_pending_concepts',{student_id:id},null,'json').done(function(response){
            debts=response.data||[];let html='';debts.forEach(debt=>html+='<option value="'+debt.ef_id+'">'+$('<div>').text(debt.concepto_concatenado+' · Saldo '+money(debt.balance)).html()+'</option>');
            if(correction){correction.concepts.forEach(item=>{let debt=debts.find(x=>String(x.ef_id)===String(item.ef_id));if(debt)debt.balance=Number(debt.balance)+Number(item.amount);else{debt={ef_id:item.ef_id,balance:Number(item.amount),concepto_concatenado:(item.course||'Concepto')+(item.year?' · '+item.year:'')};debts.push(debt);html+='<option value="'+debt.ef_id+'">'+$('<div>').text(debt.concepto_concatenado+' · Disponible para corregir '+money(debt.balance)).html()+'</option>'}})}
            $('#debt-select').html(html).prop('disabled',false).trigger('change.select2');
            if(correction){$('#debt-select').val(correction.concepts.map(x=>String(x.ef_id))).trigger('change');correction.concepts.forEach(item=>{let row=$('.debt-row[data-id="'+item.ef_id+'"]');if(item.discount_type){row.find('.discount-type').val(item.discount_type);row.find('.discount-value').val(item.discount_value);row.find('.discount-reason').val(item.discount_reason);row.find('.discount-fields').removeClass('d-none');row.find('.discount-value-label').text(item.discount_type==='final_amount'?'Monto final a cobrar':item.discount_type==='fixed'?'Monto de descuento':'Porcentaje');row.find('.discount-preview').text('Descuento original conservado en esta corrección.')}row.find('.debt-amount').val(Number(item.amount).toFixed(2))});syncTotals();}
        }).fail(()=>alert_toast('No se pudieron cargar las deudas activas.','danger'));
    });
    $('#debt-select').change(function(){
        let html='',selectedIds=$(this).val()||[];debts.filter(item=>selectedIds.some(id=>String(id)===String(item.ef_id))).forEach(debt=>{
            html+='<div class="pm-row debt-row" data-id="'+debt.ef_id+'" data-balance="'+debt.balance+'"><div class="row align-items-center"><div class="col-md-8"><strong>'+ $('<div>').text(debt.concepto_concatenado).html()+'</strong><div class="pm-balance">Saldo actual: '+money(debt.balance)+'</div></div><div class="col-md-4"><label>Monto a pagar</label><input type="number" min="0.01" step="0.01" value="'+Number(debt.balance).toFixed(2)+'" class="form-control debt-amount"><div class="pm-overpay"></div></div></div>';
            if(discountReady)html+='<div class="pm-discount"><div class="form-row align-items-end"><div class="form-group col-md-4 mb-0"><label>Descuento en este pago</label><select class="form-control discount-type"><option value="">Sin descuento</option><option value="final_amount">Monto final a cobrar (recomendado)</option><option value="fixed">Monto de descuento</option><option value="percentage">Porcentaje</option></select></div><div class="form-group col-md-3 mb-0 discount-fields d-none"><label class="discount-value-label">Monto final a cobrar</label><input type="number" min="0.01" step="0.01" class="form-control discount-value" placeholder="Ingrese cuánto cobrará"></div><div class="form-group col-md-5 mb-0 discount-fields d-none"><label>Motivo <small class="text-muted">(opcional)</small></label><input class="form-control discount-reason" maxlength="255" placeholder="Ej.: beneficio autorizado"></div></div><div class="small text-success mt-2 discount-preview"></div></div>';
            html+='</div>';
        });
        $('#debt-breakdown').html(html);syncTotals();
    });
    $(document).on('change','.discount-type',function(){let row=$(this).closest('.debt-row'),type=$(this).val();row.find('.discount-value-label').text(type==='final_amount'?'Monto final a cobrar':type==='fixed'?'Monto de descuento':'Porcentaje');row.find('.discount-value').attr('max',type==='percentage'?100:Number(row.data('balance')||0));recalculateDiscount(row)});
    $(document).on('input','.discount-value',function(){recalculateDiscount($(this).closest('.debt-row'))});
    $(document).on('input','.debt-amount',syncTotals);
    if(correction){
        correction.methods.forEach(function(item){addMethod();let row=$('.method-row').last();row.find('.method-id').val(item.method_id);row.find('.method-amount').val(Number(item.amount).toFixed(2));row.find('.method-reference').val(item.reference_number||'');row.find('.method-bank').val(item.bank_name||'')});
        $('#student_id').trigger('change');
        $('#save-payment').html('<i class="fa fa-check mr-1"></i>Guardar corrección');
    }
    $('#payment-form').submit(function(event){
        event.preventDefault();let concepts=[],splits=[],conceptSum=0,methodSum=0,invalid=false,message='';
        $('.debt-row').each(function(){
            let row=$(this),amount=Number(row.find('.debt-amount').val()||0),type=row.find('.discount-type').val()||'',value=Number(row.find('.discount-value').val()||0),reason=(row.find('.discount-reason').val()||'').trim();
            if(amount<=0)invalid=true;
            if(type&&!value){invalid=true;message='Ingrese el monto final, el descuento o el porcentaje.'}
            if(!correction&&type==='final_amount'&&value>=Number(row.data('balance')||0)){invalid=true;message='El monto final debe ser menor que el saldo actual para generar un descuento.'}
            concepts.push({ef_id:Number(row.data('id')),amount:amount,discount_type:type,discount_value:value,discount_reason:reason});conceptSum+=amount;
        });
        $('.method-row').each(function(){let method=Number($(this).find('.method-id').val()||0),amount=Number($(this).find('.method-amount').val()||0);if(!method||amount<=0)invalid=true;splits.push({method_id:method,amount:amount,reference_number:$(this).find('.method-reference').val(),bank_name:$(this).find('.method-bank').val(),operation_date:$('[name="payment_date"]').val().substring(0,10)});methodSum+=amount});
        if(!concepts.length||invalid)return alert_toast(message||'Revise conceptos, saldos y medios de pago.','warning');
        if(Math.abs(conceptSum-methodSum)>.009)return alert_toast('Los medios de pago deben sumar '+money(conceptSum)+'.','warning');
        $('#selected_concepts').val(JSON.stringify(concepts));$('#payment_splits').val(JSON.stringify(splits));start_load();$('#save-payment').prop('disabled',true);
        $.ajax({url:'payments_api.php?action=save',method:'POST',data:$(this).serialize(),dataType:'json'}).done(function(response){
            if(response.status!=1){$('#save-payment').prop('disabled',false);return alert_toast(response.message||'No se pudo registrar.','danger')}
            alert_toast(response.message,'success');if(window.reload_payments)window.reload_payments();if(response.operation_id){uni_modal('Vista previa del recibo','view_payment.php?operation_id='+response.operation_id,'modal-xl')}else{$('#uni_modal').modal('hide')}
        }).fail(function(xhr){$('#save-payment').prop('disabled',false);alert_toast((xhr.responseJSON||{}).message||'Error del servidor.','danger')}).always(end_load);
    });
})(jQuery);
</script>

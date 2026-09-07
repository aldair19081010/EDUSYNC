<?php
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
?>
<div class="payment-receipt-preview">
    <?php include __DIR__ . '/receipt.php'; ?>
</div>
<div class="payment-preview-actions no-print">
    <button class="btn btn-outline-secondary" type="button" data-dismiss="modal"><i class="fa fa-times mr-1"></i>Cerrar</button>
    <button class="btn btn-outline-primary" type="button" id="copy-payment-link"><i class="fa fa-link mr-1"></i>Copiar enlace</button>
    <button class="btn btn-outline-success" type="button" id="share-payment-whatsapp"><i class="fab fa-whatsapp mr-1"></i>Compartir boleta</button>
    <button class="btn btn-primary" type="button" id="print-payment-receipt"><i class="fa fa-print mr-1"></i>Imprimir</button>
</div>
<style>
#uni_modal .modal-dialog{max-width:calc(100vw - 28px);width:1380px;margin:12px auto}
#uni_modal .modal-content{height:calc(100vh - 24px);max-height:calc(100vh - 24px);overflow:hidden}
#uni_modal .modal-header{flex:0 0 auto;padding:.7rem 1rem}
#uni_modal .modal-body{display:flex;flex-direction:column;min-height:0;padding:0;overflow:hidden}
.payment-receipt-preview{display:flex;flex:1 1 auto;align-items:flex-start;justify-content:center;min-height:0;overflow:hidden;padding:10px;background:#eef2f7}
.payment-receipt-preview .receipt-sheet{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(330px,.85fr);column-gap:14px;flex:0 0 auto;max-width:none;margin:0;padding:14px;transform-origin:top center;box-shadow:0 3px 14px rgba(16,24,40,.08)}
.payment-receipt-preview .receipt-header,.payment-receipt-preview .receipt-meta,.payment-receipt-preview .receipt-note,.payment-receipt-preview .receipt-footer-note,.payment-receipt-preview .receipt-sheet>.alert{grid-column:1/-1}
.payment-receipt-preview .receipt-header{padding-bottom:9px}
.payment-receipt-preview .receipt-logo{width:58px;height:58px}
.payment-receipt-preview .receipt-meta{gap:6px;margin:9px 0}
.payment-receipt-preview .receipt-meta-item{padding:6px 8px}
.payment-receipt-preview .receipt-application-section{grid-column:1;grid-row:3/span 2;margin-top:5px}
.payment-receipt-preview .receipt-methods-section{grid-column:2;grid-row:3;margin-top:5px}
.payment-receipt-preview .receipt-summary{grid-column:2;grid-row:4;margin-top:8px}
.payment-receipt-preview .receipt-summary table{width:100%}
.payment-receipt-preview .receipt-section h6{margin-bottom:5px}
.payment-receipt-preview .receipt-table th,.payment-receipt-preview .receipt-table td{padding:6px}
.payment-receipt-preview .receipt-footer-note{margin-top:9px}
.payment-preview-actions{display:flex;flex:0 0 auto;justify-content:flex-end;gap:8px;padding:9px 14px;border-top:1px solid #e4e7ec;background:#fff}
@media(max-width:767px){
    #uni_modal .modal-dialog{width:calc(100% - 12px);margin:6px}
    #uni_modal .modal-content{height:calc(100vh - 12px);max-height:calc(100vh - 12px)}
    .payment-receipt-preview{display:block;overflow:auto;padding:7px}
    .payment-receipt-preview .receipt-sheet{display:block;transform:none!important;width:auto!important;height:auto!important;margin-bottom:0!important;padding:10px}
    .payment-preview-actions{overflow-x:auto;justify-content:flex-start;white-space:nowrap}
}
</style>
<script>
(function($){
    var query = <?php echo json_encode($receipt_operation_id > 0
        ? 'operation_id=' . $receipt_operation_id
        : 'ef_id=' . $receipt_debt_id . '&pid=' . $receipt_payment_id); ?>;
    var operationId = <?php echo (int)$receipt_operation_id; ?>;
    var csrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    function fitReceiptInModal(){
        if(window.innerWidth<768)return;
        var preview=document.querySelector('.payment-receipt-preview');
        var receipt=preview&&preview.querySelector('.receipt-sheet');
        if(!preview||!receipt)return;
        receipt.style.transform='none';
        receipt.style.width='1280px';
        receipt.style.height='auto';
        receipt.style.marginBottom='0';
        var availableWidth=Math.max(1,preview.clientWidth-20);
        var availableHeight=Math.max(1,preview.clientHeight-20);
        var naturalWidth=receipt.scrollWidth;
        var naturalHeight=receipt.scrollHeight;
        var scale=Math.min(1,availableWidth/naturalWidth,availableHeight/naturalHeight);
        receipt.style.transform='scale('+scale+')';
        receipt.style.width=naturalWidth+'px';
        receipt.style.marginBottom=((naturalHeight*scale)-naturalHeight)+'px';
    }
    requestAnimationFrame(function(){requestAnimationFrame(fitReceiptInModal)});
    $('#uni_modal').off('shown.bs.modal.paymentFit').on('shown.bs.modal.paymentFit',fitReceiptInModal);
    $(window).off('resize.paymentFit').on('resize.paymentFit',fitReceiptInModal);
    $('#uni_modal').off('hidden.bs.modal.paymentFit').on('hidden.bs.modal.paymentFit',function(){
        $(window).off('resize.paymentFit');
    });
    function secureShareUrl(callback){
        if(operationId<=0){alert_toast('Este recibo histórico no admite enlaces compartidos.','warning');return;}
        $.post('payments_api.php?action=create_receipt_share',{operation_id:operationId,csrf_token:csrf},null,'json').done(function(response){
            if(response.status!=1){alert_toast(response.message||'No se pudo crear el enlace seguro.','danger');return;}
            callback(new URL(response.url,window.location.href).href,response.expires_in_days||7);
        }).fail(function(xhr){alert_toast((xhr.responseJSON||{}).message||'No se pudo crear el enlace seguro.','danger')});
    }
    function receiptImageFile(){
        var receipt=document.querySelector('.payment-receipt-preview .receipt-sheet');
        if(!receipt||typeof window.html2canvas!=='function')return Promise.reject(new Error('No está disponible el generador de imágenes.'));
        var previous={transform:receipt.style.transform,width:receipt.style.width,height:receipt.style.height,marginBottom:receipt.style.marginBottom};
        receipt.style.transform='none';
        receipt.style.width='1280px';
        receipt.style.height='auto';
        receipt.style.marginBottom='0';
        return window.html2canvas(receipt,{backgroundColor:'#ffffff',scale:Math.min(2,window.devicePixelRatio||1),useCORS:true,logging:false}).then(function(canvas){
            Object.keys(previous).forEach(function(key){receipt.style[key]=previous[key]});
            return new Promise(function(resolve,reject){
                canvas.toBlob(function(blob){
                    if(!blob){reject(new Error('No se pudo generar la imagen de la boleta.'));return;}
                    var receiptNumber=<?php echo json_encode(preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$operation['receipt_full'])); ?>;
                    resolve(new File([blob],'recibo_'+receiptNumber+'.png',{type:'image/png'}));
                },'image/png',.95);
            });
        }).catch(function(error){
            Object.keys(previous).forEach(function(key){receipt.style[key]=previous[key]});
            throw error;
        });
    }
    $('#print-payment-receipt').on('click', function(){
        var popup = window.open('receipt.php?' + query, '_blank', 'width=1100,height=750');
        if (!popup) {
            alert_toast('El navegador bloqueó la ventana de impresión.', 'warning');
            return;
        }
        popup.addEventListener('load', function(){
            popup.focus();
            popup.print();
        });
    });
    $('#copy-payment-link').on('click', function(){
        secureShareUrl(function(receiptUrl,days){
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(receiptUrl).then(function(){alert_toast('Enlace seguro copiado. Vence en '+days+' días.', 'success')});
            else {var field=$('<input>').val(receiptUrl).appendTo('body').select();document.execCommand('copy');field.remove();alert_toast('Enlace seguro copiado. Vence en '+days+' días.', 'success');}
        });
    });
    $('#share-payment-whatsapp').on('click', function(){
        var button=$(this),original=button.html();
        button.prop('disabled',true).html('<i class="fa fa-spinner fa-spin mr-1"></i>Preparando...');
        receiptImageFile().then(function(file){
            var shareData={files:[file],title:'Recibo <?php echo htmlspecialchars($operation['receipt_full'], ENT_QUOTES, 'UTF-8'); ?>',text:'Recibo de pago de <?php echo htmlspecialchars($operation['student_name'], ENT_QUOTES, 'UTF-8'); ?> por S/ <?php echo number_format((float)$operation['total_amount'], 2, '.', ''); ?>'};
            if(navigator.share&&navigator.canShare&&navigator.canShare({files:[file]}))return navigator.share(shareData);
            throw new Error('FILE_SHARE_UNAVAILABLE');
        }).catch(function(error){
            if(error&&error.name==='AbortError')return;
            secureShareUrl(function(receiptUrl){
                var message='Recibo <?php echo htmlspecialchars($operation['receipt_full'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($operation['student_name'], ENT_QUOTES, 'UTF-8'); ?> - S/ <?php echo number_format((float)$operation['total_amount'], 2, '.', ''); ?>. '+receiptUrl;
                window.open('https://wa.me/?text='+encodeURIComponent(message),'_blank');
                alert_toast('Este navegador no permite adjuntar la imagen automáticamente; se compartirá el enlace seguro.','info');
            });
        }).finally(function(){button.prop('disabled',false).html(original)});
    });
})(jQuery);
</script>

<?php
require 'vendor/autoload.php';

use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Report\XmlUtils;

class SunatService {
    
    private $see;
    private $company;

    public function __construct() {
        // Inicializar See para el entorno Beta oficial de SUNAT
        $this->see = new See();
        
        // SUNAT provee endpoints de prueba "beta" sin necesidad de certificado real (Greenter implementa su uso simulado)
        // Pero para enviar la estructura correcta, See necesita cargar un certificado X.509
        $certificate = file_get_contents(__DIR__ . '/vendor/greenter/greenter/config/certs/cert.pem') ?: 
                       file_get_contents('https://raw.githubusercontent.com/giansalex/greenter-sample/master/certs/certificate.pem');
                       
        $this->see->setCertificate($certificate);
        
        // Endpoint de prueba oficial
        $this->see->setService(SunatEndpoints::FE_BETA);
        $this->see->setClaveSOL('20000000001', 'MODDATOS', 'moddatos');

        // Datos del Emisor (Configurar los datos de tu colegio aquí)
        $this->company = (new Company())
            ->setRuc('20123456789') // Cambiar por tu RUC
            ->setRazonSocial('COLEGIO EDUSYNC S.A.C.')
            ->setNombreComercial('Edusync')
            ->setAddress((new Address())
                ->setUbigueo('150101')
                ->setDepartamento('LIMA')
                ->setProvincia('LIMA')
                ->setDistrito('LIMA')
                ->setUrbanizacion('-')
                ->setDireccion('Av. Principal 123'));
    }

    /**
     * Emitir Factura o Boleta
     * @param array $data_venta Datos de la venta ({tipoCpe, serie, correlativo, cliente_doc, cliente_nombre, concepto, monto})
     * @return array [success, hash, xml, cdr, error]
     */
    public function emitirComprobante($data_venta) {
        $tipoDocCliente = (strlen($data_venta['cliente_doc']) == 11) ? '6' : '1';
        
        // Cliente
        $client = (new Client())
            ->setTipoDoc($tipoDocCliente)
            ->setNumDoc($data_venta['cliente_doc'])
            ->setRznSocial($data_venta['cliente_nombre']);

        $montoTotal = floatval($data_venta['monto']);
        $igv = $montoTotal - ($montoTotal / 1.18);
        $subtotal = $montoTotal / 1.18;

        // Venta (Boleta 03 o Factura 01)
        $invoice = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101') // Venta interna
            ->setTipoDoc($data_venta['tipoCpe']) // 01 Factura, 03 Boleta
            ->setSerie($data_venta['serie'])
            ->setCorrelativo($data_venta['correlativo'])
            ->setFechaEmision(new \DateTime())
            ->setTipoMoneda('PEN')
            ->setCompany($this->company)
            ->setClient($client)
            ->setMgoOperacionesGravadas($subtotal)
            ->setMgoOperacionesExoneradas(0.00) // Cambiar según corresponda si edusync es exonerado
            ->setMgtIgv($igv)
            ->setTotalImpuestos($igv)
            ->setValorVenta($subtotal)
            ->setSubTotal($montoTotal)
            ->setMontoImpTrasladado($igv)
            ->setMontoTotalImpuestos($igv)
            ->setRedondeo(0.00)
            ->setMpeTotalLiquidacion($montoTotal); // Mpe = Importe Total
            
        // Si el colegio es EXONERADO (como la mayoría de colegios), cambia los parámetros a Exoneradas.

        // Detalle (Ítem de la deuda pagada)
        $item = (new SaleDetail())
            ->setCodProducto('P001')
            ->setUnidad('NIU') // Bien/Servicio
            ->setCantidad(1)
            ->setDescripcion($data_venta['concepto'])
            ->setMtoBaseIgv($subtotal)
            ->setPorcentajeIgv(18.00) // 18%
            ->setIgv($igv)
            ->setTipAfeIgv('10') // Gravado - Operación Onerosa. Usar 20 para Exonerado
            ->setTotalImpuestos($igv)
            ->setMtoValorVenta($subtotal)
            ->setMtoValorUnitario($subtotal)
            ->setMtoPrecioUnitario($montoTotal);

        $invoice->setDetails([$item])
                ->setLegends([
                    (new Legend())
                        ->setCode('1000')
                        ->setValue('SON ' . $this->numeroALetras($montoTotal) . ' SOLES')
                ]);

        // Enviar
        $res = $this->see->send($invoice);

        $response = [
            'success' => false,
            'hash' => '',
            'xml_name' => $invoice->getName() . '.xml',
            'xml_content' => $this->see->getFactory()->getLastXml(),
            'cdr_name' => 'R-' . $invoice->getName() . '.zip',
            'cdr_content' => '',
            'error' => ''
        ];

        // Guardar el XML localmente (crear carpeta facturacion_docs si no existe)
        if (!is_dir(__DIR__ . '/facturacion_docs')) mkdir(__DIR__ . '/facturacion_docs');
        file_put_contents(__DIR__ . '/facturacion_docs/' . $response['xml_name'], $response['xml_content']);
        
        $response['hash'] = (new XmlUtils())->getHashSign($response['xml_content']);

        if (!$res->isSuccess()) {
            $response['error'] = 'Error: '.$res->getError()->getCode().' - '.$res->getError()->getMessage();
            return $response;
        }

        // Obtener CDR
        $response['success'] = true;
        // La CDR viene en binario dentro de $res->getCdrZip()
        $cdr_content = $res->getCdrZip();
        file_put_contents(__DIR__ . '/facturacion_docs/' . $response['cdr_name'], $cdr_content);
        $response['cdr_content'] = $cdr_content;
        
        // Guardamos también la descripción del CDR
        $cdr = $res->getCdrResponse();
        $response['cdr_desc'] = $cdr->getDescription();

        return $response;
    }

    private function numeroALetras($monto) {
        // Implementación básica (puedes reemplazarla o usar librerías externas)
        return "EL MONTO ESTABLECIDO EN LETRAS"; 
    }
}

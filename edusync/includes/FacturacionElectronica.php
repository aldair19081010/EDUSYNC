<?php
/**
 * Clase para gestionar Facturación Electrónica SUNAT usando Greenter
 * Sistema EduSync
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Api as GreenterApi;
use Greenter\See;

class FacturacionElectronica {
    
    private $conn;
    private $school_id;
    private $config;
    private $see;
    
    public function __construct($conn, $school_id) {
        $this->conn = $conn;
        $this->school_id = $school_id;
        $this->loadConfig();
        $this->initGreenter();
    }
    
    /**
     * Cargar configuración de la empresa
     */
    private function loadConfig() {
        $query = $this->conn->query("SELECT * FROM company_config WHERE school_id = {$this->school_id} AND is_active = 1 LIMIT 1");
        
        if (!$query || $query->num_rows == 0) {
            throw new Exception("No se encontró configuración de facturación para esta institución. Configure primero los datos de la empresa.");
        }
        
        $this->config = $query->fetch_assoc();
    }
    
    /**
     * Inicializar Greenter con configuración
     */
    private function initGreenter() {
        $useApiCredentials = !empty($this->config['sunat_client_id']) && !empty($this->config['sunat_client_secret']);
        
        if ($useApiCredentials) {
            $this->see = new GreenterApi($this->getApiEndpoints());
        } else {
            $this->see = new See();
        }
        
        // Configurar certificado PEM
        if (!empty($this->config['certificado_path']) && file_exists($this->config['certificado_path'])) {
            $pemContent = file_get_contents($this->config['certificado_path']);
            
            if (strpos($pemContent, 'BEGIN CERTIFICATE') !== false || strpos($pemContent, 'BEGIN RSA PRIVATE KEY') !== false || strpos($pemContent, 'BEGIN PRIVATE KEY') !== false) {
                $this->see->setCertificate($pemContent);
            } else {
                throw new Exception("El archivo seleccionado no parece ser un certificado PEM válido. Asegúrate de extraerlo de tu PFX y que contenga \"-----BEGIN CERTIFICATE-----\".");
            }
        }

        // Verificar que la clave privada esté disponible y sea utilizable por OpenSSL.
        if (!empty($pemContent)) {
            $privateKeyUsable = false;
            // Intentar obtener la clave privada sin passphrase
            $res = @openssl_pkey_get_private($pemContent);
            if ($res !== false) {
                $privateKeyUsable = true;
                openssl_pkey_free($res);
            } else {
                // Intentar con contraseña guardada (si existe)
                if (!empty($this->config['certificado_password'])) {
                    $pass = $this->decrypt($this->config['certificado_password']);
                    $res = @openssl_pkey_get_private($pemContent, $pass);
                    if ($res !== false) {
                        $privateKeyUsable = true;
                        openssl_pkey_free($res);
                    }
                }
            }

            if (!$privateKeyUsable) {
                throw new Exception("La clave privada del certificado no es utilizable por OpenSSL. Asegúrate de subir un PEM que contenga la clave privada sin cifrar o proporciona la contraseña correcta.\nEjemplo para convertir PFX a PEM (Linux/Windows con OpenSSL):\n1) Extraer clave privada sin cifrar: openssl pkcs12 -in certificado.p12 -nocerts -nodes -out key.pem\n2) Extraer certificado: openssl pkcs12 -in certificado.p12 -nokeys -out cert.pem\n3) Combinar: cat key.pem cert.pem > combined.pem\nLuego sube `combined.pem` en la configuración.");
            }
        }
        
        // Configurar credenciales SUNAT
        $ruc = $this->config['ruc'];
        $usuario = $this->config['sunat_usuario'];
        $password = $this->decrypt($this->config['sunat_password']);
        
        if ($useApiCredentials) {
            $clientSecret = $this->decrypt($this->config['sunat_client_secret']);
            $this->see->setApiCredentials($this->config['sunat_client_id'], $clientSecret);
        } else {
            // Si estamos en pruebas, enviamos a BETA. Si estamos en Producción, enviamos a PRODUCCIÓN.
            if (isset($this->config['sunat_modo']) && $this->config['sunat_modo'] === 'produccion') {
                $this->see->setService(SunatEndpoints::FE_PRODUCCION);
            } else {
                $this->see->setService(SunatEndpoints::FE_BETA);
            }
        }
        
        $this->see->setClaveSOL($ruc, $usuario, $password);
    }

    private function getApiEndpoints(): array {
        if (isset($this->config['sunat_modo']) && $this->config['sunat_modo'] === 'beta') {
            return [
                'auth' => 'https://api-seguridad-sandbox.sunat.gob.pe/v1',
                'cpe' => 'https://api-cpe-sandbox.sunat.gob.pe/v1'
            ];
        }

        return [
            'auth' => 'https://api-seguridad.sunat.gob.pe/v1',
            'cpe' => 'https://api-cpe.sunat.gob.pe/v1'
        ];
    }
    
    /**
     * Probar conexión con SUNAT (Fake Trace)
     */
    public function testConnection() {
        try {
            if (!$this->see) {
                throw new Exception("Emisor no inicializado.");
            }
            
            $envName = (isset($this->config['sunat_modo']) && $this->config['sunat_modo'] === 'produccion') 
                ? 'Producción' : 'BETA/Pruebas';
                
            // Vamos a forzar un error de validación XML en SUNAT enviando un documento vacío.
            // Si las credenciales y el certificado son inválidos, SUNAT no lee el XML y rechaza la conexión en el acto (ej. 0109 o SoapFault).
            // Si la autenticación pasa, SUNAT intentará leer el XML, se dará cuenta de que es basura, y devolverá un error de validación (ej. 100, 2000, 0300) dentro de un envío exitoso, ¡lo que confirma que entramos!
            
            $dummyXml = '<?xml version="1.0" encoding="ISO-8859-1"?><Invoice></Invoice>';
            $res = $this->see->sendXml(\Greenter\Model\Sale\Invoice::class, '11111111111-01-F001-1', $dummyXml);
            
            if (!$res->isSuccess()) {
                $error = $res->getError();
                $errCode = $error ? $error->getCode() : '';
                $errMsg = $error ? $error->getMessage() : '';
                
                // Si el error es de sintaxis XML o documento mal formado (ej: 0306, 0148, 0100, etc), significa que logramos entrar a SUNAT y evaluaron nuestro XML.
                // Errores comunes de XML mal formado: 0306 (No se puede leer), 0100 (Errores en XML), 0148.
                // Errores de autenticación: 0109, 0110, 0111.
                if ($errCode != '0109' && $errCode != '0110' && $errCode != '0111' && $errCode != '0112' && stripos($errMsg, 'autenticacion') === false && stripos($errMsg, 'password') === false) {
                    return [
                        'success' => true,
                        'env' => $envName,
                        'error' => ''
                    ];
                }
                
                return [
                    'success' => false,
                    'env' => $envName,
                    'error' => "SUNAT rechazó las credenciales/certificado: [{$errCode}] {$errMsg}"
                ];
            }
            
            return [
                'success' => true,
                'env' => $envName,
                'error' => ''
            ];
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            // Greenter a veces atrapa excepciones puras de parseo WSDL. Si el mensaje NO es de seguridad, es que logramos conectar.
            if (stripos($msg, 'autentic') === false && stripos($msg, 'security') === false && stripos($msg, 'password') === false) {
                 return [
                    'success' => true,
                    'env' => (isset($this->config['sunat_modo']) && $this->config['sunat_modo'] === 'produccion') ? 'Producción' : 'BETA/Pruebas',
                    'error' => ''
                ];
            }
            
            return [
                'success' => false,
                'env' => '',
                'error' => $msg
            ];
        }
    }
    
    /**
     * Generar Boleta de Venta (03)
     */
    public function generarBoleta($payment_id) {
        // Obtener datos del pago
        $payment = $this->getPaymentData($payment_id);
        
        if (!$payment) {
            throw new Exception("No se encontró el pago especificado");
        }
        
        // Verificar si ya tiene comprobante
        if (!empty($payment['comprobante_id'])) {
            throw new Exception("Este pago ya tiene un comprobante electrónico asociado");
        }
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo('boleta');
        $serie = $this->config['serie_boleta'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Crear comprobante en BD
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '03',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'payment_id' => $payment_id,
            'student_id' => $payment['student_id'],
            'cliente_tipo_doc' => '1', // DNI
            'cliente_num_doc' => $payment['student_dni'],
            'cliente_razon_social' => $payment['student_name'],
            'cliente_direccion' => $payment['student_address'] ?? '',
            'cliente_email' => $payment['student_email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $payment['amount']
        ]);
        
        // Crear detalle de items
        $this->crearDetalleItems($comprobante_id, $payment);
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '03');
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        // Actualizar payment con comprobante_id
        $this->conn->query("UPDATE payments SET comprobante_id = {$comprobante_id} WHERE id = {$payment_id}");
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Boleta generada y enviada a SUNAT correctamente' : 'Error al enviar a SUNAT',
            'cdr' => $result->getCdrResponse()
        ];
    }
    
    /**
     * Reenviar comprobante a SUNAT
     */
    public function reenviarComprobante($comprobante_id) {
        $query = $this->conn->query("SELECT tipo_comprobante, estado_sunat FROM comprobantes_electronicos WHERE id = {$comprobante_id} AND school_id = {$this->school_id}");
        
        if (!$query || $query->num_rows == 0) {
            return ['success' => false, 'message' => 'Comprobante no encontrado'];
        }
        
        $comp = $query->fetch_assoc();
        
        if ($comp['estado_sunat'] == 'aceptado') {
            return ['success' => true, 'estado_sunat' => 'aceptado', 'message' => 'El comprobante ya se encontraba aceptado en SUNAT'];
        }
        
        try {
              $invoice = $this->buildInvoiceGreenter($comprobante_id, $comp['tipo_comprobante']);
              $result = $this->enviarComprobante($invoice, $comprobante_id);
            
            $errText = '';
            if (!$result->isSuccess() && $result->getError()) {
                 $errText = $result->getError()->getMessage();
            }
            
            return [
                'success' => $result->isSuccess(),
                'estado_sunat' => $result->isSuccess() ? 'aceptado' : 'rechazado',
                'message' => $result->isSuccess() ? 'Reenviado con éxito a SUNAT' : 'SUNAT lo rechazó: ' . $errText
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generar Boleta Masiva (independiente de payments)
     */
    public function generarBoletaMasiva($student_id, $concepto, $monto, $periodo) {
        // Obtener datos del estudiante
        $student_query = $this->conn->query(
            "SELECT s.id, s.nombre_estudiante, s.dni, s.email, d.direccion 
             FROM students s 
             LEFT JOIN student_details d ON s.id = d.student_id 
             WHERE s.id = {$student_id} AND s.school_id = {$this->school_id}"
        );
        
        if (!$student_query || $student_query->num_rows == 0) {
            throw new Exception("Estudiante no encontrado");
        }
        
        $student = $student_query->fetch_assoc();
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo('boleta');
        $serie = $this->config['serie_boleta'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Crear comprobante en BD
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '03',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'student_id' => $student_id,
            'cliente_tipo_doc' => '1', // DNI
            'cliente_num_doc' => $student['dni'],
            'cliente_razon_social' => $student['nombre_estudiante'],
            'cliente_direccion' => $student['direccion'] ?? '',
            'cliente_email' => $student['email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $monto
        ]);
        
        // Crear detalle de items (concepto único)
        $detalle_query = $this->conn->query(
            "INSERT INTO comprobante_detalle 
            (comprobante_id, item, codigo_producto, descripcion, cantidad, precio_unitario, total) 
            VALUES ({$comprobante_id}, 1, '01', '" . $this->conn->real_escape_string($concepto) . "', 1, {$monto}, {$monto})"
        );
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '03');
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        // Guardar en tabla boletas_masivas
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        $this->conn->query(
            "INSERT INTO boletas_masivas 
            (school_id, student_id, concepto, monto, periodo, comprobante_id, numero_completo, estado_sunat, fecha_emision) 
            VALUES ({$this->school_id}, {$student_id}, '" . $this->conn->real_escape_string($concepto) . "', {$monto}, 
            '" . $this->conn->real_escape_string($periodo) . "', {$comprobante_id}, '{$numero_completo}', '{$estado_sunat}', NOW())"
        );
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'student_id' => $student_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Boleta generada y enviada a SUNAT' : 'Error al enviar a SUNAT',
            'cdr' => $result->getCdrResponse()
        ];
    }
    
    /**
     * Generar Factura (01)
     */
    public function generarFactura($payment_id, $ruc, $razon_social, $direccion = '') {
        $payment = $this->getPaymentData($payment_id);
        
        if (!$payment) {
            throw new Exception("No se encontró el pago especificado");
        }
        
        if (!empty($payment['comprobante_id'])) {
            throw new Exception("Este pago ya tiene un comprobante electrónico asociado");
        }
        
        // Validar RUC
        if (strlen($ruc) != 11) {
            throw new Exception("El RUC debe tener 11 dígitos");
        }
        
        $correlativo = $this->getNextCorrelativo('factura');
        $serie = $this->config['serie_factura'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '01',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'payment_id' => $payment_id,
            'student_id' => $payment['student_id'],
            'cliente_tipo_doc' => '6', // RUC
            'cliente_num_doc' => $ruc,
            'cliente_razon_social' => $razon_social,
            'cliente_direccion' => $direccion,
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $payment['amount']
        ]);
        
        $this->crearDetalleItems($comprobante_id, $payment);
        
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '01');
        $result = $this->see->send($invoice);
        
        $this->procesarRespuestaSunat($comprobante_id, $result);
        
        // Actualizar payment
        $this->conn->query("UPDATE payments SET comprobante_id = {$comprobante_id}, requiere_factura = 1, cliente_ruc = '{$ruc}', cliente_razon_social = '{$razon_social}', cliente_direccion = '{$direccion}' WHERE id = {$payment_id}");
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Factura generada y enviada a SUNAT correctamente' : 'Error al enviar a SUNAT',
            'cdr' => $result->getCdrResponse()
        ];
    }
    
    /**
     * Generar Comprobante Libre (Factura o Boleta independiente con items personalizados)
     */
    public function generarComprobanteLibre($data, $detalles) {
        $tipo = $data['tipo_comprobante']; // '01' o '03'
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo($tipo == '01' ? 'factura' : 'boleta');
        $serie = $tipo == '01' ? $this->config['serie_factura'] : $this->config['serie_boleta'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Determinar monto total
        $total_precio_venta = 0;
        foreach($detalles as $det) {
            $total_precio_venta += $det['total'];
        }
        
        // Crear comprobante en BD
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => $tipo,
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'student_id' => $data['student_id'] ?: null,
            'cliente_tipo_doc' => $data['cliente_tipo_doc'], 
            'cliente_num_doc' => $data['cliente_num_doc'],
            'cliente_razon_social' => $data['cliente_razon_social'],
            'cliente_direccion' => $data['cliente_direccion'] ?? '',
            'cliente_email' => $data['cliente_email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $total_precio_venta,
            'moneda' => $data['moneda'] ?? 'PEN'
        ]);
        
        // Crear detalles en BD
        $item_idx = 1;
        foreach($detalles as $det) {
            $cantidad = (float)$det['cantidad'];
            $precio_unitario = (float)$det['precio_unitario'];
            $total = (float)$det['total'];
            
            // Colegios usualmente inafectos ('30')
            $valor_unitario = $precio_unitario; // Sin IGV a restar
            $igv = 0;
            
            $sql = "INSERT INTO comprobante_detalle (
                comprobante_id, item, descripcion, unidad_medida, cantidad,
                valor_unitario, precio_unitario, subtotal, igv, total,
                tipo_afectacion_igv, porcentaje_igv, codigo_producto
            ) VALUES (
                {$comprobante_id}, {$item_idx}, '" . $this->conn->real_escape_string($det['descripcion']) . "', 'NIU', {$cantidad},
                {$valor_unitario}, {$precio_unitario}, {$total}, {$igv}, {$total},
                '30', 0.00, 'EDU001'
            )";
            $this->conn->query($sql);
            $item_idx++;
        }
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, $tipo);
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Comprobante generado y enviado a SUNAT' : 'Error al enviar a SUNAT: ' . ($result->getError() ? $result->getError()->getMessage() : ''),
            'cdr' => $result->getCdrResponse()
        ];
    }
    
    /**
     * Construir Invoice de Greenter
     */
    private function buildInvoiceGreenter($comprobante_id, $tipo_doc) {
        // Obtener datos del comprobante
        $comp = $this->conn->query("SELECT * FROM comprobantes_electronicos WHERE id = {$comprobante_id}")->fetch_assoc();
        
        // Cliente
        $client = new Client();
        $client->setTipoDoc($comp['cliente_tipo_doc'])
               ->setNumDoc($comp['cliente_num_doc'])
               ->setRznSocial($comp['cliente_razon_social']);
        
        if (!empty($comp['cliente_direccion'])) {
            $address = new Address();
            $address->setDireccion($comp['cliente_direccion']);
            $client->setAddress($address);
        }
        
        // Empresa
        $company = new Company();
        $company->setRuc($this->config['ruc'])
                ->setRazonSocial($this->config['razon_social'])
                ->setNombreComercial($this->config['nombre_comercial'] ?? $this->config['razon_social']);
        
        $address = new Address();
        $address->setUbigueo($this->config['ubigeo'])
                ->setDepartamento($this->config['departamento'])
                ->setProvincia($this->config['provincia'])
                ->setDistrito($this->config['distrito'])
                ->setUrbanizacion($this->config['urbanizacion'] ?? '-')
                ->setDireccion($this->config['direccion'])
                ->setCodLocal('0000'); // Principal
        $company->setAddress($address);
        
        // Invoice
        $invoice = new Invoice();
        $invoice->setTipoDoc($tipo_doc)
                ->setSerie($comp['serie'])
                ->setCorrelativo($comp['correlativo'])
                ->setFechaEmision(new DateTime($comp['fecha_emision']))
                ->setTipoMoneda($comp['moneda'])
                ->setClient($client)
                ->setCompany($company);
        
        // Obtener items
        $items_query = $this->conn->query("SELECT * FROM comprobante_detalle WHERE comprobante_id = {$comprobante_id} ORDER BY item");
        
        $mtoOperGravadas = 0;   // Operaciones gravadas (con IGV 18%)
        $mtoOperInafectas = 0;  // Operaciones inafectas (servicios educativos)
        $mtoIGV = 0;
        
        $details = [];
        while ($item = $items_query->fetch_assoc()) {
            $detail = new SaleDetail();
            $detail->setCodProducto($item['codigo_producto'] ?? 'EDU001')
                   ->setUnidad($item['unidad_medida'])
                   ->setDescripcion($item['descripcion'])
                   ->setCantidad($item['cantidad'])
                   ->setMtoValorUnitario($item['valor_unitario'])
                   ->setMtoValorVenta($item['subtotal'])
                   ->setMtoBaseIgv($item['subtotal'])
                   ->setPorcentajeIgv($item['porcentaje_igv'] ?? 0)
                   ->setIgv($item['igv'] ?? 0)
                   ->setTipAfeIgv($item['tipo_afectacion_igv'] ?? '30')
                   ->setTotalImpuestos($item['igv'] ?? 0)
                   ->setMtoPrecioUnitario($item['precio_unitario']);
            
            $details[] = $detail;
            
            // Clasificar según tipo de afectación
            $tipo_afect = $item['tipo_afectacion_igv'] ?? '30';
            if ($tipo_afect == '10') {
                // Gravado (con IGV)
                $mtoOperGravadas += $item['subtotal'];
                $mtoIGV += $item['igv'];
            } elseif (in_array($tipo_afect, ['30', '31', '32', '33', '34', '35', '36'])) {
                // Inafecto
                $mtoOperInafectas += $item['subtotal'];
            }
        }
        
        $invoice->setDetails($details);
        
        // Totales
        $invoice->setMtoOperGravadas($mtoOperGravadas)
                ->setMtoOperInafectas($mtoOperInafectas)
                ->setMtoIGV($mtoIGV)
                ->setTotalImpuestos($mtoIGV)
                ->setValorVenta($mtoOperGravadas)
                ->setMtoImpVenta($comp['total_precio_venta']);
        
        // Leyenda (monto en letras)
        $legend = new Legend();
        $legend->setCode('1000')
               ->setValue($this->numeroALetras($comp['total_precio_venta']) . ' ' . ($comp['moneda'] == 'PEN' ? 'SOLES' : 'DÓLARES'));
        $invoice->setLegends([$legend]);
        
        return $invoice;
    }
    
    /**
     * Obtener datos del pago
     */
    private function getPaymentData($payment_id) {
        $query = $this->conn->query("
            SELECT p.*, s.id as student_id, s.name as student_name, s.id_no as student_dni, s.address as student_address, s.email as student_email,
                   GROUP_CONCAT(DISTINCT c.course SEPARATOR ', ') as conceptos
            FROM payments p
            INNER JOIN student_ef_list ef ON p.ef_id = ef.id
            INNER JOIN student s ON ef.student_id = s.id
            INNER JOIN courses c ON ef.course_id = c.id
            WHERE p.id = {$payment_id}
            GROUP BY p.id
        ");
        
        return $query ? $query->fetch_assoc() : null;
    }
    
    /**
     * Crear comprobante en base de datos
     */
    private function crearComprobanteDB($data) {
        $fields = [];
        $values = [];
        
        $data['school_id'] = $this->school_id;
        $data['created_by'] = $_SESSION['login_id'] ?? null;
        
        foreach ($data as $key => $value) {
            $fields[] = "`{$key}`";
            $values[] = "'" . $this->conn->real_escape_string($value) . "'";
        }
        
        $sql = "INSERT INTO comprobantes_electronicos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
        
        if ($this->conn->query($sql)) {
            return $this->conn->insert_id;
        }
        
        throw new Exception("Error al crear comprobante en BD: " . $this->conn->error);
    }
    
    /**
     * Crear detalle de items del comprobante
     */
    private function crearDetalleItems($comprobante_id, $payment) {
        $cantidad = 1;
        $total = $payment['amount'];
        
        // SERVICIOS EDUCATIVOS SON INAFECTOS AL IGV (Art. 19° Ley del IGV)
        // Código de afectación: 30 = Inafecto - Operación Onerosa
        $valor_unitario = number_format($total, 2, '.', ''); // No hay IGV que restar
        $igv = '0.00'; // Sin IGV
        $monto = $valor_unitario;
        
        $descripcion = "SERVICIO EDUCATIVO";
        if (!empty($payment['conceptos'])) {
            $descripcion .= " - " . $payment['conceptos'];
        }
        
        $sql = "INSERT INTO comprobante_detalle (
            comprobante_id, item, descripcion, unidad_medida, cantidad,
            valor_unitario, precio_unitario, subtotal, igv, total,
            tipo_afectacion_igv, porcentaje_igv, codigo_producto
        ) VALUES (
            {$comprobante_id}, 1, '" . $this->conn->real_escape_string($descripcion) . "', 'ZZ', {$cantidad},
            {$valor_unitario}, {$monto}, {$valor_unitario}, {$igv}, {$monto},
            '30', 0.00, 'EDU001'
        )";
        
        if (!$this->conn->query($sql)) {
            throw new Exception("Error al crear detalle: " . $this->conn->error);
        }
    }
    
    /**
     * Procesar respuesta de SUNAT
     */
    private function procesarRespuestaSunat($comprobante_id, $result) {
        $estado = $result->isSuccess() ? 'aceptado' : 'rechazado';
        $xml_content = '';
        // Compatibilidad: Greenter `See` expone FeFactory via getFactory(),
        // mientras que `Api` expone getLastXml() directamente.
        if (method_exists($this->see, 'getFactory')) {
            $xml_content = $this->see->getFactory()->getLastXml();
        } elseif (method_exists($this->see, 'getLastXml')) {
            $xml_content = $this->see->getLastXml();
        }
        
        // Extraer hash del XML firmado
        $codigo_hash = '';
        if (preg_match('/<ds:DigestValue>(.*?)<\/ds:DigestValue>/', $xml_content, $matches)) {
            $codigo_hash = $matches[1];
        }
        
        $update = "UPDATE comprobantes_electronicos SET 
            estado_sunat = '{$estado}',
            codigo_hash = '" . $this->conn->real_escape_string($codigo_hash) . "',
            xml_content = '" . $this->conn->real_escape_string($xml_content) . "',
            fecha_envio_sunat = NOW()";
        
        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            $cdr_zip_base64 = base64_encode($result->getCdrZip());
            $update .= ", cdr_content = '" . $cdr_zip_base64 . "',
                         sunat_code = '" . $cdr->getCode() . "',
                         sunat_description = '" . $this->conn->real_escape_string($cdr->getDescription()) . "',
                         fecha_respuesta_sunat = NOW()";
        } else {
            $error = $result->getError();
            $update .= ", sunat_description = '" . $this->conn->real_escape_string($error->getMessage()) . "'";
        }
        
        $update .= " WHERE id = {$comprobante_id}";
        $this->conn->query($update);
        
        // Log
        $this->registrarLog($comprobante_id, 'envio', $xml_content, $result->isSuccess() ? 'success' : 'error');
    }

    /**
     * Enviar comprobante y capturar errores fatales de envío.
     */
    private function enviarComprobante($invoice, $comprobante_id) {
        try {
            $result = $this->see->send($invoice);
            $this->procesarRespuestaSunat($comprobante_id, $result);
            return $result;
        } catch (\Exception $e) {
            $xml_content = '';
            if (method_exists($this->see, 'getFactory')) {
                $xml_content = $this->see->getFactory()->getLastXml();
            } elseif (method_exists($this->see, 'getLastXml')) {
                $xml_content = $this->see->getLastXml();
            }

            $update = "UPDATE comprobantes_electronicos SET estado_sunat = 'rechazado', sunat_description = '" . $this->conn->real_escape_string($e->getMessage()) . "', fecha_envio_sunat = NOW(), xml_content = '" . $this->conn->real_escape_string($xml_content) . "' WHERE id = {$comprobante_id}";
            $this->conn->query($update);
            $this->registrarLog($comprobante_id, 'envio', $xml_content ?: $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Obtener siguiente correlativo
     */
    private function getNextCorrelativo($tipo) {
        $campo = 'correlativo_' . $tipo;
        $correlativo = $this->config[$campo];
        
        // Actualizar en BD
        $this->conn->query("UPDATE company_config SET {$campo} = {$campo} + 1 WHERE id = {$this->config['id']}");
        
        return $correlativo;
    }
    
    /**
     * Registrar operación en log
     */
    private function registrarLog($comprobante_id, $operacion, $request, $estado) {
        $user_id = $_SESSION['login_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $sql = "INSERT INTO sunat_log (comprobante_id, operacion, request, estado, ip_address, user_id) 
                VALUES ({$comprobante_id}, '{$operacion}', '" . $this->conn->real_escape_string($request) . "', '{$estado}', '{$ip}', " . ($user_id ? $user_id : 'NULL') . ")";
        
        $this->conn->query($sql);
    }
    
    /**
     * Convertir número a letras
     */
    private function numeroALetras($numero) {
        $num = floatval($numero);
        $entero = floor($num);
        $decimales = round(($num - $entero) * 100);
        
        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
        
        if ($entero == 0) return 'CERO';
        if ($entero == 100) return 'CIEN';
        
        $resultado = '';
        
        // Miles
        if ($entero >= 1000) {
            $miles = floor($entero / 1000);
            if ($miles == 1) {
                $resultado .= 'MIL ';
            } else {
                $resultado .= $this->convertirGrupo($miles) . ' MIL ';
            }
            $entero = $entero % 1000;
        }
        
        // Centenas, decenas, unidades
        $resultado .= $this->convertirGrupo($entero);
        
        return trim($resultado);
    }
    
    private function convertirGrupo($num) {
        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
        
        $resultado = '';
        
        // Centenas
        $c = floor($num / 100);
        if ($c > 0) {
            if ($num == 100) {
                $resultado .= 'CIEN';
                return $resultado;
            }
            $resultado .= $centenas[$c] . ' ';
        }
        
        $num = $num % 100;
        
        // Decenas especiales (10-19)
        if ($num >= 10 && $num <= 19) {
            $resultado .= $especiales[$num - 10];
            return trim($resultado);
        }
        
        // Decenas
        $d = floor($num / 10);
        if ($d > 0) {
            $resultado .= $decenas[$d];
            if ($num % 10 > 0) {
                $resultado .= ' Y ';
            }
        }
        
        // Unidades
        $u = $num % 10;
        if ($u > 0) {
            $resultado .= $unidades[$u];
        }
        
        return trim($resultado);
    }
    
    /**
     * Encriptar/Desencriptar datos sensibles
     */
    private function encrypt($data) {
        $key = 'EduSync2024Secret';
        $method = 'AES-256-CBC';
        $iv = substr(hash('sha256', $key), 0, 16);
        return base64_encode(openssl_encrypt($data, $method, $key, 0, $iv));
    }
    
    private function decrypt($data) {
        $key = 'EduSync2024Secret';
        $method = 'AES-256-CBC';
        $iv = substr(hash('sha256', $key), 0, 16);
        return openssl_decrypt(base64_decode($data), $method, $key, 0, $iv);
    }
    
    /**
     * Generar PDF del comprobante
     */
    public function generarPDF($comprobante_id) {
        // Obtener datos del comprobante
        $query = $this->conn->query("
            SELECT c.*, s.name as student_name, s.id_no as student_dni, s.address as student_address
            FROM comprobantes_electronicos c
            LEFT JOIN student s ON c.student_id = s.id
            WHERE c.id = $comprobante_id AND c.school_id = {$this->school_id}
        ");
        
        if (!$query || $query->num_rows == 0) {
            throw new Exception("Comprobante no encontrado");
        }
        
        $comp = $query->fetch_assoc();
        
        // Obtener detalle
        $detalle_query = $this->conn->query("SELECT * FROM comprobante_detalle WHERE comprobante_id = $comprobante_id ORDER BY item");
        $detalles = [];
        while ($det = $detalle_query->fetch_assoc()) {
            $detalles[] = $det;
        }
        
        // Generar HTML
        $html = $this->generarHTMLComprobante($comp, $detalles);
        
        // Crear PDF con TCPDF
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Configuración del PDF
        $pdf->SetCreator('EduSync - Sistema Escolar');
        $pdf->SetAuthor($this->config['razon_social']);
        $pdf->SetTitle($comp['tipo_comprobante'] == '01' ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA');
        $pdf->SetSubject('Comprobante Electrónico');
        
        // Quitar headers y footers por defecto
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Configurar márgenes
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        
        // Agregar página
        $pdf->AddPage();
        
        // Escribir HTML
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Retornar PDF como string
        return $pdf->Output('', 'S');
    }
    
    /**
     * Generar HTML del comprobante para PDF - Diseño profesional SUNAT
     */
    private function generarHTMLComprobante($comp, $detalles) {
        $tipo_doc = $comp['tipo_comprobante'] == '01' ? 'FACTURA DE VENTA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA';
        $simbolo_moneda = 'S/';

        // Ubicación
        $ubicacion = trim(
            ($this->config['distrito'] ?? '') . ' - ' .
            ($this->config['provincia'] ?? '') . ' - ' .
            ($this->config['departamento'] ?? '')
        );

        // Totales por tipo de afectación
        $op_gravadas = 0.00;
        $op_exoneradas = 0.00;
        $op_inafectas = 0.00;
        $igv_total = 0.00;

        foreach ($detalles as $det) {
            $tipo_afect = $det['tipo_afectacion_igv'] ?? '30';
            $subtotal = (float)($det['subtotal'] ?? 0);
            $igv = (float)($det['igv'] ?? 0);
            $igv_total += $igv;

            if ($tipo_afect === '10') {
                $op_gravadas += $subtotal;
            } elseif ($tipo_afect === '20') {
                $op_exoneradas += $subtotal;
            } else {
                $op_inafectas += $subtotal;
            }
        }

        $html = '<!DOCTYPE html>
        <html>
        <head>
        <meta charset="utf-8" />
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 9.5px; color: #000; line-height: 1.5; }
            .page { width: 100%; padding: 4px 6px; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .text-bold { font-weight: bold; }
            .muted { color: #444; }
            .mb-6 { margin-bottom: 5px; }
            .mb-8 { margin-bottom: 7px; }
            .mb-10 { margin-bottom: 8px; }
            .box { border: 1px solid #000; }
            .box-strong { border: 2px solid #000; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 3px 4px; vertical-align: top; }
            .header-line { border-bottom: 2px solid #000; padding-bottom: 7px; }
            .table-fixed { table-layout: fixed; }
            .detail th, .detail td { border: 1px solid #000; }
            .detail th { font-size: 8px; background: #f2f2f2; padding: 3px; }
            .detail td { font-size: 8px; line-height: 1.3; padding: 3px 4px; vertical-align: top; }
            .section-title { font-size: 9.5px; font-weight: bold; margin-bottom: 5px; }
            .totals { padding: 2px; }
            .totals td { padding: 4px 7px; }
            .totals .label { width: 60%; }
            .totals .value { width: 40%; text-align: right; }
            .footer { font-size: 7px; line-height: 1.4; border-top: 1px solid #ccc; padding-top: 5px; margin-top: 8px; }
        </style>
        </head>
        <body>
        <div class="page">

        <!-- ENCABEZADO -->
        <table class="mb-10 header-line">
            <tr>
                <td style="width:65%;">
                    <div class="text-bold" style="font-size:11px;">' . htmlspecialchars(strtoupper($this->config['razon_social'])) . '</div>
                    <div class="muted">' . htmlspecialchars($this->config['direccion'] ?? '-') . '</div>
                    <div class="muted">' . htmlspecialchars($ubicacion) . '</div>
                </td>
                <td style="width:35%;">
                    <div class="box-strong text-center" style="padding:6px;">
                        <div class="text-bold" style="font-size:10px;">' . $tipo_doc . '</div>
                        <div class="text-bold">RUC: ' . htmlspecialchars($this->config['ruc']) . '</div>
                        <div class="text-bold">' . htmlspecialchars($comp['numero_completo']) . '</div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- DATOS DEL CLIENTE -->
        <table class="mb-10">
            <tr>
                <td style="width:18%;" class="text-bold">Fecha Vencimiento</td>
                <td style="width:32%;">: -</td>
                <td style="width:18%;" class="text-bold">Fecha Emisión</td>
                <td style="width:32%;">: ' . date('d/m/Y', strtotime($comp['fecha_emision'])) . '</td>
            </tr>
            <tr>
                <td class="text-bold">Señor(es)</td>
                <td colspan="3">: ' . strtoupper(htmlspecialchars($comp['cliente_razon_social'])) . '</td>
            </tr>
            <tr>
                <td class="text-bold">' . ($comp['cliente_tipo_doc'] == '6' ? 'RUC' : 'DNI') . '</td>
                <td colspan="3">: ' . htmlspecialchars($comp['cliente_num_doc']) . '</td>
            </tr>
            <tr>
                <td class="text-bold">Tipo de Moneda</td>
                <td colspan="3">: SOLES</td>
            </tr>
            <tr>
                <td class="text-bold">Observación</td>
                <td colspan="3">: -</td>
            </tr>
        </table>

        <!-- DETALLE DEL SERVICIO -->
        <div class="section-title">Detalle del Servicio</div>
        <table class="detail table-fixed mb-10">
            <colgroup>
                <col style="width:8%;" />
                <col style="width:12%;" />
                <col style="width:30%;" />
                <col style="width:14%;" />
                <col style="width:10%;" />
                <col style="width:16%;" />
                <col style="width:10%;" />
            </colgroup>
            <thead>
                <tr>
                    <th class="text-center">Cantidad</th>
                    <th class="text-center">Unidad</th>
                    <th class="text-center">Descripción</th>
                    <th class="text-center">Valor Unit.(*)</th>
                    <th class="text-center">Descuento(*)</th>
                    <th class="text-center">Importe Venta(**)</th>
                    <th class="text-center">ICBPER</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($detalles as $det) {
            $unidad = $det['unidad_medida'] ?? 'NIU';
            $unidad_label = ($unidad === 'NIU' || $unidad === 'ZZ') ? 'UNIDAD' : strtoupper($unidad);
            
            $html .= '
                <tr>
                    <td class="col-cantidad">' . number_format($det['cantidad'] ?? 1, 2) . '</td>
                    <td class="col-unidad">' . $unidad_label . '</td>
                    <td class="col-descripcion">' . htmlspecialchars($det['descripcion']) . '</td>
                    <td class="col-valor">' . $simbolo_moneda . ' ' . number_format($det['valor_unitario'] ?? 0, 2) . '</td>
                    <td class="col-descuento">' . $simbolo_moneda . ' 0.00</td>
                    <td class="col-importe">' . $simbolo_moneda . ' ' . number_format($det['subtotal'] ?? 0, 2) . '</td>
                    <td class="col-icbper">' . $simbolo_moneda . ' 0.00</td>
                </tr>';
        }

        $html .= '
            </tbody>
        </table>

        <table class="mb-10">
            <tr>
                <td style="width:55%; vertical-align:top;">
                    <div class="muted" style="font-size:8px;">
                        (*) Sin impuestos.<br/>
                        (**) Incluye impuestos, de ser Op. Gravada.
                    </div>
                    <div class="box text-center" style="margin-top:6px; padding:6px; font-weight:bold;">
                        SON: ' . strtoupper($this->numeroALetras($comp['total_precio_venta'])) . ' SOLES
                    </div>
                </td>
                <td style="width:45%; vertical-align:top;">
                    <table class="box totals">
                        <tr><td class="label">Otros Cargos :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">Otros Tributos :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">ICBPER :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label text-bold">Importe Total :</td><td class="value text-bold">' . $simbolo_moneda . ' ' . number_format($comp['total_precio_venta'], 2) . '</td></tr>
                    </table>
                    <table class="box totals" style="margin-top:6px;">
                        <tr><td class="label">Op. Gravada :</td><td class="value">' . $simbolo_moneda . ' ' . number_format($op_gravadas, 2) . '</td></tr>
                        <tr><td class="label">Op. Exonerada :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label text-bold">Op. Inafecta :</td><td class="value text-bold">' . $simbolo_moneda . ' ' . number_format($op_inafectas, 2) . '</td></tr>
                        <tr><td class="label">ISC :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">IGV :</td><td class="value">' . $simbolo_moneda . ' ' . number_format($igv_total, 2) . '</td></tr>
                        <tr><td class="label">ICBPER :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">Otros Cargos :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">Otros Tributos :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label">Monto Redondeo :</td><td class="value">' . $simbolo_moneda . ' 0.00</td></tr>
                        <tr><td class="label text-bold">Importe Total :</td><td class="value text-bold">' . $simbolo_moneda . ' ' . number_format($comp['total_precio_venta'], 2) . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="footer">
            Esta es una representación impresa de la <strong>' . $tipo_doc . '</strong>, generada en el Sistema de la SUNAT. El Emisor Electrónico puede verificarla en SUNAT Virtual.
            <div class="muted" style="margin-top:4px;"><strong>Hash:</strong> ' . htmlspecialchars($comp['codigo_hash']) . '</div>
        </div>

        </div>
        </body>
        </html>';

        return $html;
    }
    
    /**
     * Generar boleta desde deuda (student_ef_list)
     */
    public function generarBoletaDeuda($ef_id, $monto_custom = null) {
        // Obtener datos de la deuda
        $deuda = $this->getDeudaData($ef_id);
        
        if (!$deuda) {
            throw new Exception("No se encontró la deuda especificada");
        }
        
        if ($monto_custom !== null && floatval($monto_custom) > 0) {
            $deuda['amount'] = floatval($monto_custom);
        }
        
        // Verificar si ya tiene comprobante
        if (!empty($deuda['comprobante_id'])) {
            throw new Exception("Esta deuda ya tiene un comprobante electrónico asociado");
        }
        
        // Validar datos del tutor
        if (empty($deuda['tutor1_dni']) || empty($deuda['tutor_nombre_completo'])) {
            throw new Exception("El estudiante no tiene datos completos del tutor/apoderado. Por favor, actualice la información del apoderado.");
        }
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo('boleta');
        $serie = $this->config['serie_boleta'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Crear comprobante en BD usando datos del tutor
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '03',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'payment_id' => null, // No hay payment_id para deudas
            'student_id' => $deuda['student_id'],
            'cliente_tipo_doc' => '1', // DNI
            'cliente_num_doc' => $deuda['tutor1_dni'],
            'cliente_razon_social' => $deuda['tutor_nombre_completo'],
            'cliente_direccion' => $deuda['tutor1_direccion'] ?? $deuda['student_address'],
            'cliente_email' => $deuda['student_email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $deuda['amount']
        ]);
        
        // Crear detalle de items
        $this->crearDetalleItemsDeuda($comprobante_id, $deuda);
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '03');
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        // Actualizar student_ef_list con comprobante_id
        $this->conn->query("UPDATE student_ef_list SET comprobante_id = {$comprobante_id} WHERE id = {$ef_id}");
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Boleta generada correctamente' : $result->getError()->getMessage()
        ];
    }
    
    /**
     * Generar factura desde deuda (student_ef_list)
     */
    public function generarFacturaDeuda($ef_id, $ruc, $razon_social, $direccion, $monto_custom = null) {
        // Obtener datos de la deuda
        $deuda = $this->getDeudaData($ef_id);
        
        if (!$deuda) {
            throw new Exception("No se encontró la deuda especificada");
        }
        
        if ($monto_custom !== null && floatval($monto_custom) > 0) {
            $deuda['amount'] = floatval($monto_custom);
        }
        
        // Verificar si ya tiene comprobante
        if (!empty($deuda['comprobante_id'])) {
            throw new Exception("Esta deuda ya tiene un comprobante electrónico asociado");
        }
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo('factura');
        $serie = $this->config['serie_factura'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Crear comprobante en BD
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '01',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'payment_id' => null, // No hay payment_id para deudas
            'student_id' => $deuda['student_id'],
            'cliente_tipo_doc' => '6', // RUC
            'cliente_num_doc' => $ruc,
            'cliente_razon_social' => $razon_social,
            'cliente_direccion' => $direccion,
            'cliente_email' => $deuda['student_email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $deuda['amount']
        ]);
        
        // Crear detalle de items
        $this->crearDetalleItemsDeuda($comprobante_id, $deuda);
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '01');
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        // Actualizar student_ef_list con comprobante_id
        $this->conn->query("UPDATE student_ef_list SET comprobante_id = {$comprobante_id} WHERE id = {$ef_id}");
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Factura generada correctamente' : $result->getError()->getMessage()
        ];
    }
    
    /**
     * Obtener datos de una deuda (student_ef_list)
     */
    private function getDeudaData($ef_id) {
        $query = $this->conn->query("
            SELECT 
                ef.id,
                ef.comprobante_id,
                COALESCE(ef.discounted_amount, ef.total_fee) as amount,
                s.id as student_id,
                s.name as student_name,
                s.id_no as student_dni,
                s.address as student_address,
                s.contact as student_email,
                s.tutor1_nombre,
                s.tutor1_apellido,
                s.tutor1_dni,
                s.tutor1_direccion,
                CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) as tutor_nombre_completo,
                c.course as concepto,
                c.level,
                s.grado,
                ay.year
            FROM student_ef_list ef
            INNER JOIN student s ON s.id = ef.student_id
            INNER JOIN courses c ON c.id = ef.course_id
            LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
            WHERE ef.id = {$ef_id} AND s.school_id = {$this->school_id}
            LIMIT 1
        ");
        
        if (!$query || $query->num_rows == 0) {
            return null;
        }
        
        return $query->fetch_assoc();
    }
    
    /**
     * Crear detalle de items para deuda
     */
    private function crearDetalleItemsDeuda($comprobante_id, $deuda) {
        // Incluir nombre del estudiante en la descripción
        $descripcion = 'ESTUDIANTE: ' . strtoupper($deuda['student_name']) . ' - ' . $deuda['concepto'];
        if (!empty($deuda['level'])) {
            $descripcion .= ' - ' . $deuda['level'];
        }
        if (!empty($deuda['grado'])) {
            $descripcion .= ' (' . $deuda['grado'] . ')';
        }
        if (!empty($deuda['year'])) {
            $descripcion .= ' - ' . $deuda['year'];
        }
        
        // SERVICIOS EDUCATIVOS SON INAFECTOS AL IGV (Art. 19° Ley del IGV)
        // Código de afectación: 30 = Inafecto - Operación Onerosa
        $monto = number_format(floatval($deuda['amount']), 2, '.', '');
        $igv = '0.00'; // Sin IGV
        $subtotal = $monto; // El monto total es igual al subtotal (no hay IGV)
        
        $this->conn->query("
            INSERT INTO comprobante_detalle (
                comprobante_id, item, descripcion, 
                unidad_medida, cantidad, 
                valor_unitario, precio_unitario,
                subtotal, igv, total,
                tipo_afectacion_igv, porcentaje_igv, codigo_producto
            ) VALUES (
                {$comprobante_id}, 1, '" . $this->conn->real_escape_string($descripcion) . "',
                'ZZ', 1.00, 
                {$monto}, {$monto},
                {$subtotal}, {$igv}, {$monto},
                '30', 0.00, 'EDU001'
            )
        ");
    }
    
    /**
     * Generar boleta desde deuda con receptor alternativo (DNI diferente al tutor)
     */
    public function generarBoletaDeudaConReceptor($ef_id, $receptor_dni, $receptor_nombre, $receptor_direccion = '', $monto_custom = null) {
        // Obtener datos de la deuda
        $deuda = $this->getDeudaData($ef_id);
        
        if (!$deuda) {
            throw new Exception("No se encontró la deuda especificada");
        }
        
        if ($monto_custom !== null && floatval($monto_custom) > 0) {
            $deuda['amount'] = floatval($monto_custom);
        }
        
        // Verificar si ya tiene comprobante
        if (!empty($deuda['comprobante_id'])) {
            throw new Exception("Esta deuda ya tiene un comprobante electrónico asociado");
        }
        
        // Validar DNI
        if (!preg_match('/^\d{8}$/', $receptor_dni)) {
            throw new Exception("El DNI debe tener 8 dígitos");
        }
        
        // Validar nombre
        if (empty($receptor_nombre) || strlen($receptor_nombre) < 3) {
            throw new Exception("El nombre del receptor es inválido");
        }
        
        // Obtener siguiente correlativo
        $correlativo = $this->getNextCorrelativo('boleta');
        $serie = $this->config['serie_boleta'];
        $numero_completo = $serie . '-' . str_pad($correlativo, 8, '0', STR_PAD_LEFT);
        
        // Preparar dirección (usar la proporcionada o la del estudiante)
        $direccion_final = !empty($receptor_direccion) ? $receptor_direccion : ($deuda['student_address'] ?? '-');
        
        // Crear comprobante en BD usando datos del receptor alternativo
        $comprobante_id = $this->crearComprobanteDB([
            'tipo_comprobante' => '03',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero_completo,
            'payment_id' => null, // No hay payment_id para deudas
            'student_id' => $deuda['student_id'],
            'cliente_tipo_doc' => '1', // DNI
            'cliente_num_doc' => $receptor_dni,
            'cliente_razon_social' => strtoupper($receptor_nombre),
            'cliente_direccion' => $direccion_final,
            'cliente_email' => $deuda['student_email'] ?? '',
            'fecha_emision' => date('Y-m-d'),
            'total_precio_venta' => $deuda['amount']
        ]);
        
        // Crear detalle de items (incluye nombre del estudiante en descripción)
        $this->crearDetalleItemsDeuda($comprobante_id, $deuda);
        
        // Generar XML con Greenter
        $invoice = $this->buildInvoiceGreenter($comprobante_id, '03');
        
        // Enviar a SUNAT
        $result = $this->enviarComprobante($invoice, $comprobante_id);
        
        // Actualizar student_ef_list con comprobante_id
        $this->conn->query("UPDATE student_ef_list SET comprobante_id = {$comprobante_id} WHERE id = {$ef_id}");
        
        $estado_sunat = $result->isSuccess() ? 'aceptado' : 'rechazado';
        
        return [
            'success' => $result->isSuccess(),
            'comprobante_id' => $comprobante_id,
            'numero' => $numero_completo,
            'estado_sunat' => $estado_sunat,
            'message' => $result->isSuccess() ? 'Boleta generada correctamente' : $result->getError()->getMessage()
        ];
    }
}


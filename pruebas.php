<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------------------
// Función para enviar aviso por correo
// ---------------------------
function enviarAviso($asunto, $mensaje)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.fossilnatura.com';              // servidor SMTP
        $mail->SMTPAuth   = true;
        $mail->Username   = 'd.rodriguez@fossilnatura.com';         // tu usuario de correo
        $mail->Password   = 'asdasqwsg';             // contraseña de aplicación
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('d.rodriguez@fossilnatura.com', 'API Dachser Fossilnatura');
        $mail->addAddress('informatica@fossilnatura.com');

        $mail->isHTML(false);
        $mail->Subject = $asunto;
        $mail->Body    = $mensaje;

        $mail->send();
    } catch (Exception $e) {
        error_log("Error enviando correo: {$mail->ErrorInfo}");
    }
}

// ---------------------------
// Conexión a la base de datos
// ---------------------------
include_once("conexion.php");
$conn = Cconexion::ConexionBD();
if (!$conn) {
    die("No se pudo conectar a la base de datos.");
}

// ---------------------------
// Configuración API Dachser
// ---------------------------
$url = "https://api-gateway.dachser.com/rest/v2/transportorders/labelled";
$apiKey = "6ede82fd-374c-44ad-b8ca-c353a794bd36";

try {
    // ---------------------------
    // Obtener cabecera del pedido
    // ---------------------------
    $sqlCabecera = "
        SELECT TOP 1 
            numeroalbaran,
            nombre,
            domicilioenvios,
            municipioenvios,
            codigopostalenvios,
            SiglaNacion,
            telefonoenvios,
            seriealbaran,
            codigoempresa,
            FN_ObservTransporte,
            FN_Palets,
            FN_KilosCBL,
            FN_FechaEntrega
        FROM cabeceraalbaranclienteDaniel
        WHERE ejerciciopedido = YEAR(GETDATE())
          AND seriealbaran = YEAR(GETDATE())
          AND codigotransportistaenvios = 22
          AND FN_ImpresoCBL = 0
        ORDER BY numeroalbaran ASC
    ";

    $stmt = $conn->prepare($sqlCabecera);
    $stmt->execute();
    $cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cabecera) {
        die("No se encontró ninguna cabecera con esos filtros.");
    }

    // ---------------------------
    // VALIDACIONES
    // ---------------------------

    // Validar teléfono
    $telefono = preg_replace('/\D/', '', $cabecera['telefonoenvios']); // quitar no numéricos
    if (empty($telefono) || strlen($telefono) !== 9) {
        $msg = "Error: El telefono esta vacio o no tiene 9 caracteres. Pedido: " . $cabecera['numeroalbaran'];
        enviarAviso("Error en validacion de telefono", $msg);
        die($msg);
    }

    // Validar seriealbaran = año actual
    $anioActual = date("Y");
    if ($cabecera['seriealbaran'] != $anioActual) {
        $msg = "Error: La serie del pedido no coincide con el año actual ($anioActual). Pedido: " . $cabecera['numeroalbaran'];
        enviarAviso("Error en validacion de seriealbaran", $msg);
        die($msg);
    }

    // Validar código postal
    $cp = $cabecera['codigopostalenvios'];
    $pais = strtoupper(trim($cabecera['SiglaNacion']));

    if ($pais === "ES" || $pais === "FR") {
        // Debe tener exactamente 5 dígitos y no contener caracteres especiales
        if (!preg_match('/^[0-9]{5}$/', $cp)) {
            $msg = "Error: Codigo postal invalido ($cp) para pais $pais. Debe tener 5 numeros. Pedido: " . $cabecera['numeroalbaran'];
            enviarAviso("Error en validacion de CP", $msg);
            die($msg);
        }
    }

    if ($pais === "PT") {
        // Formato: 4 números + "-" + 3 números
        if (!preg_match('/^[0-9]{4}-[0-9]{3}$/', $cp)) {
            $msg = "Error: Codigo postal invalido ($cp) para Portugal. Debe tener formato NNNN-NNN. Pedido: " . $cabecera['numeroalbaran'];
            enviarAviso("Error en validacion de CP", $msg);
            die($msg);
        }
    }

    if (!isset($cabecera['FN_KilosCBL'])) {
        $msg = "Error: No existe el campo FN_KilosCBL en cabecera. Pedido: " . $cabecera['numeroalbaran'];
        enviarAviso("Error en validacion de kilos", $msg);
        die($msg);
    }

    $kilos = $cabecera['FN_KilosCBL'];

    if (!is_numeric($kilos) || floor($kilos) != $kilos) {
        $msg = "Error: El campo FN_KilosCBL ($kilos) debe ser un número entero (sin decimales). Pedido: " . $cabecera['numeroalbaran'];
        enviarAviso("Error en validacion de kilos", $msg);
        die($msg);
    }

    // VALIDACIÓN FECHA DE ENTREGA
    $producto = "Y";  // por defecto
    $entrega  = "";   // por defecto vacío

    if (!empty($cabecera['FN_FechaEntrega'])) {
        $fechaEntrega = new DateTime($cabecera['FN_FechaEntrega']);
        $hoy = new DateTime('today');
        $maxFecha = (clone $hoy)->modify('+3 years');

        if ($fechaEntrega < $hoy) {
            $msg = "Error: La fecha de entrega (" . $fechaEntrega->format('Y-m-d') . ") no puede estar en el pasado. Pedido: " . $cabecera['numeroalbaran'];
            enviarAviso("Error en validacion de fecha de entrega", $msg);
            die($msg);
        }

        if ($fechaEntrega > $maxFecha) {
            $msg = "Error: La fecha de entrega (" . $fechaEntrega->format('Y-m-d') . ") no puede ser más de 3 años en el futuro. Pedido: " . $cabecera['numeroalbaran'];
            enviarAviso("Error en validacion de fecha de entrega", $msg);
            die($msg);
        }

        // Verificar si la fecha cae en viernes (5), sábado (6) o domingo (0)
        $diaSemana = (int)$fechaEntrega->format('w'); // 0 = Domingo, 6 = Sábado
        if (in_array($diaSemana, [0, 5, 6])) {
            $msg = "Error: La fecha de entrega (" . $fechaEntrega->format('Y-m-d') . ") no puede caer en viernes, sábado o domingo. Pedido: " . $cabecera['numeroalbaran'];
            enviarAviso("Error en validacion de fecha de entrega", $msg);
            die($msg);
        }

        $producto = "V";
        $entrega  = $fechaEntrega->format('Y-m-d');
    }

    $palets = $cabecera['FN_Palets'];

    // ---------------------------
    // Construir transportOrderLines (solo primera línea, 1 unidad)
    // ---------------------------
    //$linea = $lineas[0];
    $transportOrderLines = [[
        "quantity"  => $palets,
        "packaging" => "EU",
        "content"   => 0,
        "weight"    => [
            "weight" => $kilos,
            "unit"   => "KG"
        ]
    ]];

    $direccion = $cabecera['domicilioenvios'];

    // Cortamos los primeros 30 caracteres para streets
    $street = substr($direccion, 0, 30);

    // Si sobra algo, lo metemos en supplementInformation
    $supplement = strlen($direccion) > 30 ? substr($direccion, 30) : "";

    // Asignamos la id de empresa 
    $consignor =  $cabecera['codigoempresa'] == 1 ? 47334931 : 47334689;

    // ---------------------------
    // Construir JSON dinámico para la API
    // ---------------------------
    $hoy = date("Y-m-d");
    $data = [
        "transportDate" => $hoy,
        "labelFormat" => "P",
        "division" => "T",
        "product" => $producto,
        "term" => "031",
        "forwarder" => ["id" => "837"],
        "consignor" => ["id" => $consignor],
        "consignee" => [
            "names" => [$cabecera['nombre']],
            "addressInformation" => [
                "streets" => [$street],
                "supplementInformation" => $supplement,
                "city" => $cabecera['municipioenvios'],
                "postalCode" => $cabecera['codigopostalenvios'],
                "countryCode" => $cabecera['SiglaNacion']
            ]
        ],
        "references" => [["code" => "100", "value" => $cabecera['seriealbaran'] . '/' . $cabecera['numeroalbaran']]],
        "transportOrderLines" => $transportOrderLines,
        "transportOptions" => [
            /*"collectionOption" => "CN",*/
            "deliveryNoticeOptions" => [[
                "type" => "AS",
                "name" => $cabecera['nombre'],
                "mobilePhone" => $cabecera['telefonoenvios'],
                "mail" => ""
            ]],
            "deliveryTailLift" => false,
            "selfCollector" => false,
            "frostProtected" => false,
            "storageSpace" => 0,
            "fixedDate" => $entrega
        ],
        "goodsValueInsurance" => [
            "amount" => 0,
            "currency" => "EUR"
        ],
        "furtherAddresses" => [],
        "texts" => [["code" => "ZU", "value" => "Pedido " . $cabecera['numeroalbaran']]]
    ];

    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

    // ---------------------------
    // Envío de datos a la API
    // ---------------------------
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "x-api-key: $apiKey",
        "Content-Length: " . strlen($jsonData)
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        exit("Error en cURL: " . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode($response, true);

    // ---------------------------
    // Procesar respuesta y enviar PDF
    // ---------------------------
    if (isset($decoded['label'])) {
        $pdfData = base64_decode($decoded['label']);
        if ($pdfData === false) {
            die("Error: no se pudo decodificar el PDF");
        }

        if (ob_get_length()) ob_end_clean();

        // Nombre del archivo PDF
        $fileName = "etiqueta_" . $decoded['id'] . ".pdf";

        // ---------------------------
        // Actualizar estado de la etiqueta en la BBDD
        // ---------------------------
        $sqlupdate = "
            UPDATE cabeceraalbaranclienteDaniel
            SET FN_ImpresoCBL = -1
            WHERE numeroalbaran = :numeroalbaran
              AND codigoempresa = 1
              AND ejerciciopedido = 2025
              AND seriealbaran = '2025'
        ";
        $stmtUpdate = $conn->prepare($sqlupdate);
        $stmtUpdate->bindParam(":numeroalbaran", $cabecera['numeroalbaran']);
        $stmtUpdate->execute();

        // Guardar etiquetas en ruta específica
        $directorio = __DIR__ . '/etiquetas/'; // Carpeta donde guardar
        if (!file_exists($directorio)) {
            mkdir($directorio, 0777, true); // Crear carpeta si no existe
        }

        // Carpeta para copias
        $dirCopias = __DIR__ . '/etiquetas/copias/';
        if (!file_exists($dirCopias)) {
            mkdir($dirCopias, 0777, true);
        }

        $fileName = "etiqueta_" . $decoded['id'] . ".pdf";
        $rutaCompleta = $directorio . $fileName;
        $rutaCopias = $dirCopias . $fileName;

        // Descargar la etiqueta
        file_put_contents($rutaCompleta, $pdfData);
        file_put_contents($rutaCopias, $pdfData);
        echo "Etiqueta guardada en: $rutaCompleta";
    } else {
        // Enviar correo con JSON y respuesta de la API
        $msg = "Error: La API no ha devuelto una etiqueta PDF valida.\n\n" .
            "JSON enviado:\n$jsonData\n\n" .
            "Respuesta de la API:\n" . print_r($decoded, true);
        enviarAviso("Error en API Dachser", $msg);
        die("Se ha enviado un aviso por correo: La API no devolvió una etiqueta PDF válida.");
    }
} catch (PDOException $e) {
    echo "Error en la consulta: " . $e->getMessage();
} catch (Exception $e) {
    echo "Error general: " . $e->getMessage();
}

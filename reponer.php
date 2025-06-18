<?php
session_start();

// --- ZONA DE SEGURIDAD Y FUNCIONES (reutilizadas de index.php) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function leerDatosMoneda(string $currency, string $file = 'monedas'): ?array
{
    $monedasPermitidas = ['euro', 'dolar', 'yen'];
    if (!in_array($currency, $monedasPermitidas))
        return null;
    $filename = $file === 'monedas' ? 'monedas.txt' : 'stock.txt';
    if (!file_exists($filename))
        return null;
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $datos = [];
    $encontrada = false;
    foreach ($lines as $line) {
        if ($encontrada) {
            if (is_numeric($line))
                $datos[] = (int) $line;
            else
                break;
        }
        if (trim($line) === $currency)
            $encontrada = true;
    }
    return $datos;
}

function escribirStock(string $currency, array $valoresMonedas, array $nuevoStock): bool
{
    $monedasPermitidas = ['euro', 'dolar', 'yen'];
    if (!in_array($currency, $monedasPermitidas))
        return false;
    $filename = 'stock.txt';
    $tempFilename = 'stock.tmp';
    $fp = fopen($filename, 'r');
    $tempFp = fopen($tempFilename, 'w');
    if (!$fp || !$tempFp)
        return false;
    if (flock($fp, LOCK_EX)) {
        $encontrada = false;
        while (($line = fgets($fp)) !== false) {
            $trimmedLine = trim($line);
            if (!$encontrada && $trimmedLine === $currency) {
                $encontrada = true;
                fwrite($tempFp, $line);
                foreach ($nuevoStock as $cantidad) {
                    fwrite($tempFp, $cantidad . PHP_EOL);
                }
                foreach ($valoresMonedas as $_) {
                    fgets($fp);
                }
            } else {
                fwrite($tempFp, $line);
            }
        }
        flock($fp, LOCK_UN);
    } else {
        return false;
    }
    fclose($fp);
    fclose($tempFp);
    rename($tempFilename, $filename);
    return true;
}

// --- LÓGICA DE PROCESAMIENTO ---
$monedaSeleccionada = null;
$valoresMonedas = null;
$stockActual = null;
$mensaje = '';
$error = '';

// Paso 1: Seleccionar moneda (puede ser por GET o POST)
if (isset($_REQUEST['moneda'])) {
    $monedaSeleccionada = filter_input(
        isset($_POST['moneda']) ? INPUT_POST : INPUT_GET,
        'moneda',
        FILTER_SANITIZE_STRING
    );
    $valoresMonedas = leerDatosMoneda($monedaSeleccionada, 'monedas');
    $stockActual = leerDatosMoneda($monedaSeleccionada, 'stock');

    if ($valoresMonedas === null) {
        $error = "La moneda seleccionada no es válida.";
        $monedaSeleccionada = null;
    }
}

// Paso 2: Procesar la actualización del stock (solo POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Error de validación (CSRF). Inténtelo de nuevo.";
    } else {
        $stockInput = $_POST['stock'];
        $nuevoStock = [];
        $valido = true;
        // Sanitizar y validar cada entrada de stock
        foreach ($stockInput as $valor => $cantidad) {
            $cantidadFiltrada = filter_var($cantidad, FILTER_VALIDATE_INT);
            if ($cantidadFiltrada === false || $cantidadFiltrada < 0) {
                $error = "La cantidad para la moneda de $valor debe ser un número entero no negativo.";
                $valido = false;
                break;
            }
            $nuevoStock[(int) $valor] = $cantidadFiltrada;
        }

        if ($valido) {
            // Reordenar el array de nuevo stock para que coincida con el orden de valoresMonedas
            $stockOrdenado = [];
            foreach ($valoresMonedas as $valor) {
                $stockOrdenado[] = $nuevoStock[$valor];
            }

            if (escribirStock($monedaSeleccionada, $valoresMonedas, $stockOrdenado)) {
                $mensaje = "¡El stock para '$monedaSeleccionada' ha sido actualizado correctamente!";
                // Recargar el stock para mostrar los nuevos valores
                $stockActual = leerDatosMoneda($monedaSeleccionada, 'stock');
            } else {
                $error = "Hubo un error al escribir en el fichero de stock.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reponer Stock</title>
    <style>
        :root {
            --primary-color: #005A9C;
            --secondary-color: #3B82F6;
            --light-color: #EFF6FF;
            --dark-color: #1E3A8A;
            --text-color: #333;
            --white-color: #FFFFFF;
            --border-radius: 8px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--light-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 600px;
            background-color: var(--white-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 1.5rem;
        }

        form .form-group {
            margin-bottom: 1.5rem;
        }

        form label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }

        form input[type="number"],
        form select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #D1D5DB;
            border-radius: var(--border-radius);
            box-sizing: border-box;
            font-size: 1rem;
        }

        form button {
            width: 100%;
            padding: 0.8rem;
            background-color: var(--secondary-color);
            color: var(--white-color);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        form button:hover {
            background-color: var(--dark-color);
        }

        .error,
        .success {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 5px solid;
        }

        .error {
            background-color: #FEF2F2;
            border-color: #DC2626;
            color: #991B1B;
        }

        .success {
            background-color: #F0FDF4;
            border-color: #16A34A;
            color: #14532D;
        }

        .footer-link {
            text-align: center;
            margin-top: 2rem;
        }

        .footer-link a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 600;
        }

        .footer-link a:hover {
            text-decoration: underline;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stock-item input {
            width: 40%;
        }
    </style>
</head>

<body>

    <div class="container">
        <h1>Reponer Stock</h1>

        <?php if ($mensaje): ?>
            <div class="success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="reponer.php" method="get">
            <div class="form-group">
                <label for="moneda">Seleccione la Divisa para Reponer</label>
                <select name="moneda" id="moneda" onchange="this.form.submit()">
                    <option value="">-- Seleccionar --</option>
                    <option value="euro" <?php echo ($monedaSeleccionada === 'euro' ? 'selected' : ''); ?>>Euro</option>
                    <option value="dolar" <?php echo ($monedaSeleccionada === 'dolar' ? 'selected' : ''); ?>>Dólar
                    </option>
                    <option value="yen" <?php echo ($monedaSeleccionada === 'yen' ? 'selected' : ''); ?>>Yen</option>
                </select>
            </div>
        </form>

        <?php if ($monedaSeleccionada && $valoresMonedas && $stockActual): ?>
            <hr>
            <h3>Actualizar stock para: <?php echo htmlspecialchars(ucfirst($monedaSeleccionada)); ?></h3>
            <form action="reponer.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="moneda" value="<?php echo htmlspecialchars($monedaSeleccionada); ?>">

                <?php foreach ($valoresMonedas as $index => $valor): ?>
                    <div class="form-group stock-item">
                        <label for="stock_<?php echo $valor; ?>">Monedas de <?php echo $valor; ?>:</label>
                        <input type="number" name="stock[<?php echo $valor; ?>]" id="stock_<?php echo $valor; ?>"
                            value="<?php echo $stockActual[$index]; ?>" min="0" required>
                    </div>
                <?php endforeach; ?>

                <button type="submit">Actualizar Stock</button>
            </form>
        <?php endif; ?>

        <div class="footer-link">
            <a href="index.php">&larr; Volver a la calculadora</a>
        </div>
    </div>

</body>

</html>
<?php
// Iniciar la sesión para la protección CSRF. Debe ser lo primero.
session_start();

// --- ZONA DE SEGURIDAD Y FUNCIONES ---

// Generar un token CSRF si no existe uno.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Función para leer los datos de monedas o stock de los ficheros de texto.
 * Es una función segura que valida el nombre de la moneda para evitar ataques de Path Traversal.
 *
 * @param string $currency El nombre de la moneda (ej. 'euro').
 * @param string $file El fichero a leer ('monedas' o 'stock').
 * @return array|null Un array con los datos o null si la moneda no es válida.
 */
function leerDatosMoneda(string $currency, string $file = 'monedas'): ?array
{
    // LISTA BLANCA DE MONEDAS: ¡Medida de seguridad CRÍTICA!
    // Evita que un atacante pueda intentar leer otros ficheros del sistema (ej. ../../etc/passwd)
    $monedasPermitidas = ['euro', 'dolar', 'yen'];
    if (!in_array($currency, $monedasPermitidas)) {
        return null; // Moneda no válida, abortar.
    }

    $filename = $file === 'monedas' ? 'monedas.txt' : 'stock.txt';
    if (!file_exists($filename)) {
        return null;
    }

    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $datos = [];
    $encontrada = false;
    foreach ($lines as $line) {
        if ($encontrada) {
            if (is_numeric($line)) {
                $datos[] = (int) $line;
            } else {
                break; // Fin de la sección de esta moneda
            }
        }
        if (trim($line) === $currency) {
            $encontrada = true;
        }
    }
    return $datos;
}

/**
 * Función para escribir el nuevo stock en el fichero.
 * Utiliza un bloqueo de fichero (flock) para evitar condiciones de carrera si dos
 * usuarios realizan una operación simultáneamente, lo que podría corromper el fichero.
 *
 * @param string $currency La moneda a actualizar.
 * @param array $valoresMonedas Array con los valores de las monedas (200, 100, etc.)
 * @param array $nuevoStock Array con las nuevas cantidades de stock.
 * @return bool True si se escribió correctamente, false en caso de error.
 */
function escribirStock(string $currency, array $valoresMonedas, array $nuevoStock): bool
{
    $monedasPermitidas = ['euro', 'dolar', 'yen'];
    if (!in_array($currency, $monedasPermitidas)) {
        return false;
    }

    $filename = 'stock.txt';
    $tempFilename = 'stock.tmp';

    $fp = fopen($filename, 'r');
    $tempFp = fopen($tempFilename, 'w');

    if (!$fp || !$tempFp)
        return false;

    // Bloquear el fichero original para escritura exclusiva.
    if (flock($fp, LOCK_EX)) {
        $encontrada = false;
        while (($line = fgets($fp)) !== false) {
            $trimmedLine = trim($line);
            if (!$encontrada && $trimmedLine === $currency) {
                $encontrada = true;
                fwrite($tempFp, $line); // Escribir el nombre de la moneda
                foreach ($nuevoStock as $cantidad) {
                    fwrite($tempFp, $cantidad . PHP_EOL);
                }
                // Saltar las líneas del stock antiguo en el fichero original
                foreach ($valoresMonedas as $_) {
                    fgets($fp);
                }
            } else {
                fwrite($tempFp, $line);
            }
        }
        flock($fp, LOCK_UN); // Desbloquear
    } else {
        return false;
    }

    fclose($fp);
    fclose($tempFp);

    // Reemplazar el fichero original con el temporal
    rename($tempFilename, $filename);
    return true;
}


// --- LÓGICA DE PROCESAMIENTO DEL FORMULARIO ---
$resultados = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verificación del Token CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Error de validación (CSRF). Inténtelo de nuevo.";
    } else {
        // 2. Sanitización y Validación de Entradas
        $moneda = filter_input(INPUT_POST, 'moneda', FILTER_SANITIZE_STRING);
        $modo = filter_input(INPUT_POST, 'modo', FILTER_SANITIZE_STRING);
        $cantidad = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT);

        if (!$moneda || !$modo || $cantidad === false || $cantidad <= 0) {
            $error = "Todos los campos son obligatorios y la cantidad debe ser un número positivo.";
        } else {
            $valoresMonedas = leerDatosMoneda($moneda, 'monedas');
            if ($valoresMonedas === null) {
                $error = "La moneda seleccionada no es válida.";
            } else {
                $solucion = [];
                $suma = 0;
                $cambioPosible = false;

                if ($modo === 'infinito') {
                    $cantidadRestante = $cantidad;
                    foreach ($valoresMonedas as $valor) {
                        if ($cantidadRestante >= $valor) {
                            $numMonedas = floor($cantidadRestante / $valor);
                            $solucion[$valor] = $numMonedas;
                            $cantidadRestante -= $numMonedas * $valor;
                        }
                    }
                    if ($cantidadRestante == 0)
                        $cambioPosible = true;

                } elseif ($modo === 'limitado') {
                    $stockActual = leerDatosMoneda($moneda, 'stock');
                    if (count($valoresMonedas) !== count($stockActual)) {
                        $error = "Inconsistencia de datos entre monedas y stock.";
                    } else {
                        $stockTemporal = $stockActual;
                        $cantidadRestante = $cantidad;
                        foreach ($valoresMonedas as $i => $valor) {
                            $monedasAUsar = floor($cantidadRestante / $valor);
                            $monedasDisponibles = $stockTemporal[$i];
                            $monedasReales = min($monedasAUsar, $monedasDisponibles);

                            if ($monedasReales > 0) {
                                $solucion[$valor] = $monedasReales;
                                $cantidadRestante -= $monedasReales * $valor;
                                $stockTemporal[$i] -= $monedasReales;
                            }
                        }

                        if ($cantidadRestante == 0) {
                            $cambioPosible = true;
                            // Si el cambio fue posible, actualizar el fichero de stock
                            if (!escribirStock($moneda, $valoresMonedas, $stockTemporal)) {
                                $error = "El cambio se calculó, pero hubo un error al actualizar el stock.";
                            } else {
                                $resultados['stock_actualizado'] = true;
                                // Combinar el stock actualizado con los valores de moneda para mostrarlo
                                $resultados['stock_final'] = array_combine($valoresMonedas, $stockTemporal);
                            }
                        }
                    }
                }

                if ($cambioPosible) {
                    $resultados['solucion'] = $solucion;
                } else if (!$error) {
                    $error = "No es posible dar el cambio exacto para esa cantidad con las monedas disponibles.";
                }
                $resultados['moneda'] = $moneda;
                $resultados['cantidad'] = $cantidad;
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
    <title>Cajero de Cambio</title>
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

        form .radio-group label {
            display: inline-block;
            margin-right: 1rem;
            font-weight: normal;
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

        .results,
        .error,
        .success {
            margin-top: 2rem;
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

        .results {
            background-color: #F0F8FF;
            border-color: var(--secondary-color);
        }

        .results h2 {
            margin-top: 0;
            color: var(--primary-color);
        }

        .results ul {
            list-style-type: none;
            padding: 0;
        }

        .results li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #E0E7FF;
        }

        .results li:last-child {
            border-bottom: none;
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
    </style>
</head>

<body>

    <div class="container">
        <h1>Cajero de Cambio</h1>

        <form action="index.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="form-group">
                <label for="moneda">Seleccione la Divisa</label>
                <select name="moneda" id="moneda" required>
                    <option value="euro">Euro</option>
                    <option value="dolar">Dólar</option>
                    <option value="yen">Yen</option>
                </select>
            </div>

            <div class="form-group">
                <label>Modo de operación</label>
                <div class="radio-group">
                    <input type="radio" id="infinito" name="modo" value="infinito" checked>
                    <label for="infinito">Monedas Infinitas</label>
                    <input type="radio" id="limitado" name="modo" value="limitado">
                    <label for="limitado">Stock Limitado</label>
                </div>
            </div>

            <div class="form-group">
                <label for="cantidad">Cantidad a devolver (en céntimos/unidades mínimas)</label>
                <input type="number" id="cantidad" name="cantidad" min="1" required>
            </div>

            <button type="submit">Calcular Cambio</button>
        </form>

        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($resultados && isset($resultados['solucion'])): ?>
            <div class="results">
                <h2>Cambio para <?php echo htmlspecialchars($resultados['cantidad']); ?>
                    <?php echo htmlspecialchars($resultados['moneda']); ?>s:</h2>
                <ul>
                    <?php foreach ($resultados['solucion'] as $valor => $cantidad_moneda): ?>
                        <li><strong><?php echo htmlspecialchars($cantidad_moneda); ?></strong> x moneda(s) de
                            <strong><?php echo htmlspecialchars($valor); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (isset($resultados['stock_actualizado']) && $resultados['stock_actualizado']): ?>
                    <div class="success">¡El stock ha sido actualizado correctamente!</div>
                <?php endif; ?>
                <?php if (isset($resultados['stock_final'])): ?>
                    <h3>Stock Restante:</h3>
                    <ul>
                        <?php foreach ($resultados['stock_final'] as $valor => $stock): ?>
                            <li>Moneda de <strong><?php echo htmlspecialchars($valor); ?></strong>:
                                <strong><?php echo htmlspecialchars($stock); ?></strong> unidades</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer-link">
            <a href="reponer.php">Ir a reponer stock &rarr;</a>
        </div>
    </div>

</body>

</html>
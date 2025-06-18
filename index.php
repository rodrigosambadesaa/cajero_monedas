<?php
session_start();
require_once 'funciones.php';

if (!function_exists('bcadd')) {
    die('Error: La extensión BCMath de PHP es necesaria.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Cargar datos al inicio
$all_currencies = get_all_currencies();
$all_stock = get_all_stock();

// --- INICIO: Variables para persistencia del formulario ---
$selected_currency_code = 'EUR'; // Valor por defecto
$selected_mode = 'infinito';     // Valor por defecto
$cantidad_str = '';              // Valor por defecto
// --- FIN: Variables para persistencia del formulario ---

$resultados = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Error de validación (CSRF).";
    } else {
        $currency_code = filter_input(INPUT_POST, 'moneda', FILTER_SANITIZE_STRING);
        $modo = filter_input(INPUT_POST, 'modo', FILTER_SANITIZE_STRING);
        $cantidad_post = filter_input(INPUT_POST, 'cantidad', FILTER_SANITIZE_STRING);

        // --- INICIO: Actualizar variables de persistencia con datos del POST ---
        $selected_currency_code = $currency_code;
        $selected_mode = $modo;
        $cantidad_str = $cantidad_post;
        // --- FIN: Actualizar variables de persistencia ---

        if (!isset($all_currencies[$currency_code])) {
            $error = "La divisa seleccionada no es válida.";
        } elseif (!preg_match('/^\d+$/', $cantidad_post) || ltrim($cantidad_post, '0') === '') {
            $error = "La cantidad debe ser un número positivo.";
        } else {
            $denominations = $all_currencies[$currency_code]['denominations'];
            $solucion = [];
            $cambioPosible = false;

            if ($modo === 'infinito') {
                $cantidadRestante_str = $cantidad_post;
                foreach ($denominations as $valor) {
                    $valor_str = (string) $valor;
                    if (bccomp($cantidadRestante_str, $valor_str) >= 0) {
                        $numMonedas_str = bcdiv($cantidadRestante_str, $valor_str, 0);
                        $solucion[$valor] = $numMonedas_str;
                        $decremento = bcmul($numMonedas_str, $valor_str);
                        $cantidadRestante_str = bcsub($cantidadRestante_str, $decremento);
                    }
                }
                if (bccomp($cantidadRestante_str, '0') == 0)
                    $cambioPosible = true;

            } elseif ($modo === 'limitado') {
                $stockTemporal = $all_stock;
                $cantidadRestante_str = $cantidad_post;

                foreach ($denominations as $valor) {
                    $valor_str = (string) $valor;
                    $monedasAUsar_str = bcdiv($cantidadRestante_str, $valor_str, 0);
                    $monedasDisponibles_str = $stockTemporal[$currency_code][$valor_str];

                    $monedasReales_str = (bccomp($monedasAUsar_str, $monedasDisponibles_str) > 0) ? $monedasDisponibles_str : $monedasAUsar_str;

                    if (bccomp($monedasReales_str, '0') > 0) {
                        $solucion[$valor] = $monedasReales_str;
                        $decremento = bcmul($monedasReales_str, $valor_str);
                        $cantidadRestante_str = bcsub($cantidadRestante_str, $decremento);
                        $stockTemporal[$currency_code][$valor_str] = bcsub($stockTemporal[$currency_code][$valor_str], $monedasReales_str);
                    }
                }

                if (bccomp($cantidadRestante_str, '0') == 0) {
                    $cambioPosible = true;
                    if (!write_all_stock($stockTemporal)) {
                        $error = "El cambio se calculó, pero hubo un error al actualizar el stock.";
                    } else {
                        $all_stock = $stockTemporal;
                        $resultados['stock_actualizado'] = true;
                        $resultados['stock_final'] = $all_stock[$currency_code];
                    }
                }
            }

            if ($cambioPosible) {
                $resultados['solucion'] = $solucion;
            } else if (!$error) {
                $error = "No es posible dar el cambio exacto para esa cantidad.";
            }
            $resultados['moneda_nombre'] = $all_currencies[$currency_code]['name'];
            $resultados['moneda_simbolo'] = $all_currencies[$currency_code]['symbol'];
            $resultados['cantidad'] = $cantidad_post;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cajero de Cambio Universal</title>
    <style>
        :root {
            --primary-color: #005A9C;
            --secondary-color: #3B82F6;
            --light-color: #EFF6FF;
            --dark-color: #1E3A8A;
            --text-color: #333;
            --white-color: #FFFFFF;
            --border-radius: 8px;
            --danger-color: #DC2626;
            --danger-light: #FEE2E2;
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

        form input[type="text"],
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

        .form-buttons {
            display: flex;
            gap: 1rem;
        }

        form button {
            flex-grow: 1;
            padding: 0.8rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        button[type="submit"] {
            background-color: var(--secondary-color);
            color: var(--white-color);
        }

        button[type="submit"]:hover {
            background-color: var(--dark-color);
        }

        button[type="reset"] {
            background-color: var(--danger-light);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        button[type="reset"]:hover {
            background-color: var(--danger-color);
            color: var(--white-color);
        }

        .results,
        .error,
        .success {
            margin-top: 2rem;
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 5px solid;
            word-break: break-all;
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
            display: flex;
            justify-content: space-between;
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
        <h1>Cajero de Cambio Universal</h1>
        <form action="index.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="moneda">Seleccione la Divisa</label>
                <select name="moneda" id="moneda" required>
                    <?php foreach ($all_currencies as $code => $details): ?>
                        <?php // INICIO: Lógica para mantener la selección ?>
                        <option value="<?php echo $code; ?>" <?php echo ($code === $selected_currency_code) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($details['name']) . " ($code)"; ?>
                        </option>
                        <?php // FIN: Lógica para mantener la selección ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Modo de operación</label>
                <div class="radio-group">
                    <?php // INICIO: Lógica para mantener la selección del radio button ?>
                    <input type="radio" id="infinito" name="modo" value="infinito" <?php echo ($selected_mode === 'infinito') ? 'checked' : ''; ?>>
                    <label for="infinito">Monedas Infinitas</label>
                    <input type="radio" id="limitado" name="modo" value="limitado" <?php echo ($selected_mode === 'limitado') ? 'checked' : ''; ?>>
                    <label for="limitado">Stock Limitado</label>
                    <?php // FIN: Lógica para mantener la selección del radio button ?>
                </div>
            </div>
            <div class="form-group">
                <label for="cantidad">Cantidad a devolver (en la unidad mínima)</label>
                <?php // INICIO: Lógica para mantener el valor introducido ?>
                <input type="text" id="cantidad" name="cantidad" inputmode="numeric" pattern="[0-9]*" required
                    value="<?php echo htmlspecialchars($cantidad_str); ?>">
                <?php // FIN: Lógica para mantener el valor introducido ?>
            </div>

            <div class="form-buttons">
                <button type="submit">Calcular Cambio</button>
                <button type="reset">Resetear</button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="error"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div> <?php endif; ?>

        <?php if ($resultados && isset($resultados['solucion'])): ?>
            <div class="results">
                <h2>Cambio para <?php echo htmlspecialchars($resultados['cantidad']); ?> en
                    <?php echo htmlspecialchars($resultados['moneda_nombre']); ?>:</h2>
                <ul>
                    <?php foreach ($resultados['solucion'] as $valor => $cantidad_moneda): ?>
                        <li>
                            <span><strong><?php echo htmlspecialchars($cantidad_moneda); ?></strong> x billete/moneda de</span>
                            <span><strong><?php echo htmlspecialchars(bcdiv((string) $valor, '100', 2)) . ' ' . $resultados['moneda_simbolo']; ?></strong></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (isset($resultados['stock_actualizado'])): ?>
                    <div class="success">¡El stock ha sido actualizado correctamente!</div>
                <?php endif; ?>
                <?php if (isset($resultados['stock_final'])): ?>
                    <h3>Stock Restante:</h3>
                    <ul>
                        <?php foreach ($resultados['stock_final'] as $valor => $stock): ?>
                            <li>
                                <span>Moneda de
                                    <?php echo htmlspecialchars(bcdiv($valor, '100', 2)) . ' ' . $resultados['moneda_simbolo']; ?>:</span>
                                <span><strong><?php echo htmlspecialchars($stock); ?></strong> unidades</span>
                            </li>
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
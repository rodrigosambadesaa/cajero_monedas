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

$currency_code = null;
$mensaje = '';
$error = '';

if (isset($_REQUEST['moneda'])) {
    $temp_code = filter_input(isset($_POST['moneda']) ? INPUT_POST : INPUT_GET, 'moneda', FILTER_SANITIZE_STRING);
    if (isset($all_currencies[$temp_code])) {
        $currency_code = $temp_code;
    } else if (!empty($temp_code)) { // Mostrar error solo si se envió un código no vacío
        $error = "La divisa seleccionada no es válida.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Error de validación (CSRF).";
    } elseif ($currency_code) {
        $stockInput = $_POST['stock'];
        $valido = true;

        foreach ($stockInput as $denomination => $cantidad_str) {
            if (!preg_match('/^\d+$/', $cantidad_str)) {
                $error = "La cantidad para la denominación $denomination debe ser un número entero no negativo.";
                $valido = false;
                break;
            }
            // Actualizar el stock para la moneda seleccionada
            $all_stock[$currency_code][(string) $denomination] = $cantidad_str;
        }

        if ($valido) {
            if (write_all_stock($all_stock)) {
                $mensaje = "¡El stock para '" . htmlspecialchars($all_currencies[$currency_code]['name']) . "' ha sido actualizado!";
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
    <title>Reponer Stock Universal</title>
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

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            background-color: var(--dark-color);
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }

        nav a {
            color: var(--white-color);
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        nav a:hover,
        nav a.active {
            background-color: var(--secondary-color);
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

        .error,
        .success {
            margin-top: 1rem;
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

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stock-item input {
            width: 50%;
        }
    </style>
</head>

<body>
    <div class="container">
        <nav>
            <a href="index.php">Calculadora de Cambio</a>
            <a href="cambio.php">Cambio de Divisa</a>
            <a href="reponer.php" class="active">Reponer Stock</a>
        </nav>

        <h1>Reponer Stock</h1>

        <?php if ($mensaje): ?>
            <div class="success"><?php echo htmlspecialchars($mensaje); ?></div> <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div> <?php endif; ?>

        <form action="reponer.php" method="get">
            <div class="form-group">
                <label for="moneda">Seleccione la Divisa para Reponer</label>
                <select name="moneda" id="moneda" onchange="this.form.submit()">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($all_currencies as $code => $details): ?>
                        <option value="<?php echo $code; ?>" <?php echo ($currency_code === $code ? 'selected' : ''); ?>>
                            <?php echo htmlspecialchars($details['name']) . " ($code)"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($currency_code): ?>
            <hr>
            <h3>Actualizar stock para: <?php echo htmlspecialchars($all_currencies[$currency_code]['name']); ?></h3>
            <form action="reponer.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="moneda" value="<?php echo htmlspecialchars($currency_code); ?>">

                <?php foreach ($all_currencies[$currency_code]['denominations'] as $denomination):
                    $denomination_str = (string) $denomination;
                    $current_stock = $all_stock[$currency_code][$denomination_str] ?? '0';
                    ?>
                    <div class="form-group stock-item">
                        <label for="stock_<?php echo $denomination_str; ?>">
                            Billetes/monedas de
                            <?php echo htmlspecialchars(bcdiv($denomination_str, '100', 2)) . ' ' . $all_currencies[$currency_code]['symbol']; ?>:
                        </label>
                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="stock[<?php echo $denomination_str; ?>]"
                            id="stock_<?php echo $denomination_str; ?>" value="<?php echo htmlspecialchars($current_stock); ?>"
                            required>
                    </div>
                <?php endforeach; ?>

                <div class="form-buttons">
                    <button type="submit">Actualizar Stock</button>
                    <button type="reset">Resetear Valores</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <script>
        // Validación de stock: solo enteros no negativos
        document.addEventListener('DOMContentLoaded', function () {
            const stockForm = document.querySelector('form[action="reponer.php"][method="post"]');
            if (stockForm) {
                stockForm.addEventListener('submit', function (e) {
                    let valid = true;
                    let firstInvalid = null;
                    const inputs = stockForm.querySelectorAll('input[name^="stock["]');
                    inputs.forEach(input => {
                        const value = input.value.trim();
                        if (!/^\d+$/.test(value)) {
                            valid = false;
                            if (!firstInvalid) firstInvalid = input;
                            input.style.borderColor = '#DC2626';
                        } else {
                            input.style.borderColor = '';
                        }
                    });
                    if (!valid) {
                        e.preventDefault();
                        alert('Por favor, ingrese solo números enteros no negativos en todos los campos de stock.');
                        if (firstInvalid) firstInvalid.focus();
                    }
                });
            }
        });
    </script>
</body>

</html>
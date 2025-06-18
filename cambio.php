<?php
session_start();
require_once 'funciones.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Cargar datos al inicio
$all_currencies = get_all_currencies();

// Variables para el formulario "pegajoso" y los resultados
$from_currency = 'EUR';
$to_currency = 'USD';
$amount = '1';
$conversion_result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Error de validación (CSRF).";
    } else {
        // Sanitizar y validar entradas
        $amount_post = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_STRING);
        $from_currency_post = filter_input(INPUT_POST, 'from_currency', FILTER_SANITIZE_STRING);
        $to_currency_post = filter_input(INPUT_POST, 'to_currency', FILTER_SANITIZE_STRING);

        // Actualizar variables para el formulario pegajoso
        $amount = $amount_post;
        $from_currency = $from_currency_post;
        $to_currency = $to_currency_post;

        if (!preg_match('/^[0-9]*\.?[0-9]+$/', $amount) || (float) $amount <= 0) {
            $error = 'La cantidad debe ser un número positivo.';
        } elseif (!isset($all_currencies[$from_currency]) || !isset($all_currencies[$to_currency])) {
            $error = 'Una de las divisas seleccionadas no es válida.';
        } elseif ($from_currency === $to_currency) {
            $error = 'Las divisas de origen y destino no pueden ser las mismas.';
        } else {
            // Construir la URL de la API de Frankfurter
            $api_url = "https://api.frankfurter.app/latest?amount=" . urlencode($amount) . "&from=" . urlencode($from_currency) . "&to=" . urlencode($to_currency);

            // @ suprime warnings si la API falla, lo manejamos nosotros
            $response = @file_get_contents($api_url);
            if ($response === FALSE) {
                $error = "No se pudo contactar con el servicio de cambio de divisa. Inténtelo más tarde.";
            } else {
                $data = json_decode($response, true);
                if (isset($data['rates'][$to_currency])) {
                    $conversion_result = [
                        'amount' => $amount,
                        'from' => $from_currency,
                        'from_symbol' => $all_currencies[$from_currency]['symbol'],
                        'to' => $to_currency,
                        'to_symbol' => $all_currencies[$to_currency]['symbol'],
                        'rate' => $data['rates'][$to_currency] / $amount, // Tasa por unidad
                        'result' => $data['rates'][$to_currency]
                    ];
                } else {
                    $error = "No se pudo obtener la tasa de cambio para las divisas seleccionadas.";
                }
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
    <title>Cambio de Divisa y Gráfico Histórico</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            max-width: 800px;
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

        .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .form-group {
            flex-grow: 1;
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

        form button {
            padding: 0.75rem 1.5rem;
            background-color: var(--secondary-color);
            color: var(--white-color);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        form button:hover {
            background-color: var(--dark-color);
        }

        .error,
        .results-box {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border-left: 5px solid;
        }

        .error {
            background-color: #FEF2F2;
            border-color: #DC2626;
            color: #991B1B;
        }

        .results-box {
            background-color: #F0FDF4;
            border-color: #16A34A;
        }

        .results-box p {
            margin: 0;
            font-size: 1.2rem;
            color: #333;
        }

        .results-box .main-result {
            font-size: 2rem;
            font-weight: bold;
            color: var(--dark-color);
        }

        .results-box .rate {
            font-size: 1rem;
            color: #555;
            margin-top: 0.5rem;
        }

        #chart-container {
            margin-top: 2rem;
        }
    </style>
</head>

<body>
    <div class="container">

        <nav>
            <a href="index.php">Calculadora de Cambio</a>
            <a href="cambio.php" class="active">Cambio de Divisa</a>
            <a href="reponer.php">Reponer Stock</a>
        </nav>

        <h1>Cambio de Divisa en Tiempo Real</h1>

        <form action="cambio.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-row">
                <div class="form-group" style="flex-basis: 25%;">
                    <label for="amount">Cantidad</label>
                    <input type="text" name="amount" id="amount" value="<?php echo htmlspecialchars($amount); ?>">
                </div>
                <div class="form-group" style="flex-basis: 35%;">
                    <label for="from_currency">De</label>
                    <select name="from_currency" id="from_currency">
                        <?php foreach ($all_currencies as $code => $details): ?>
                            <option value="<?php echo $code; ?>" <?php echo ($code === $from_currency) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($details['name']) . " ($code)"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex-basis: 35%;">
                    <label for="to_currency">A</label>
                    <select name="to_currency" id="to_currency">
                        <?php foreach ($all_currencies as $code => $details): ?>
                            <option value="<?php echo $code; ?>" <?php echo ($code === $to_currency) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($details['name']) . " ($code)"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Convertir</button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="error"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($conversion_result): ?>
            <div class="results-box">
                <p>
                    <?php echo htmlspecialchars($conversion_result['amount']) . ' ' . htmlspecialchars($conversion_result['from']); ?>
                    es igual a:
                </p>
                <p class="main-result">
                    <?php echo htmlspecialchars(number_format($conversion_result['result'], 2)) . ' ' . htmlspecialchars($conversion_result['to']); ?>
                </p>
                <p class="rate">
                    Tasa de cambio: 1 <?php echo htmlspecialchars($conversion_result['from']); ?> =
                    <?php echo htmlspecialchars(number_format($conversion_result['rate'], 4)) . ' ' . htmlspecialchars($conversion_result['to']); ?>
                </p>
            </div>

            <div id="chart-container">
                <canvas id="historicalChart"></canvas>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // Este bloque de script solo se ejecuta si hubo una conversión exitosa
        <?php if ($conversion_result): ?>
            document.addEventListener('DOMContentLoaded', function () {
                // Pasar las variables de PHP a JavaScript de forma segura
                const fromCurrency = '<?php echo addslashes($conversion_result['from']); ?>';
                const toCurrency = '<?php echo addslashes($conversion_result['to']); ?>';

                // Calcular las fechas para el historial (últimos 90 días)
                const endDate = new Date().toISOString().split('T')[0];
                const startDate = new Date(Date.now() - 90 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

                // Construir la URL de la API para el historial
                const historyApiUrl = `https://api.frankfurter.app/${startDate}..${endDate}?from=${fromCurrency}&to=${toCurrency}`;

                // Obtener los datos del historial con Fetch API
                fetch(historyApiUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.rates) {
                            const rates = data.rates;
                            const sortedDates = Object.keys(rates).sort();

                            const chartLabels = sortedDates;
                            const chartData = sortedDates.map(date => rates[date][toCurrency]);

                            renderChart(chartLabels, chartData, fromCurrency, toCurrency);
                        }
                    })
                    .catch(error => {
                        console.error('Error al obtener datos históricos:', error);
                        document.getElementById('chart-container').innerHTML = '<p class="error">No se pudo cargar el gráfico histórico.</p>';
                    });

                // Función para dibujar el gráfico con Chart.js
                function renderChart(labels, data, from, to) {
                    const ctx = document.getElementById('historicalChart').getContext('2d');

                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: `Tasa de cambio 1 ${from} a ${to}`,
                                data: data,
                                borderColor: '#005A9C',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.1
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {
                                x: {
                                    display: true,
                                    title: {
                                        display: true,
                                        text: 'Fecha'
                                    }
                                },
                                y: {
                                    display: true,
                                    title: {
                                        display: true,
                                        text: 'Tasa de Cambio'
                                    }
                                }
                            }
                        }
                    });
                }
            });
        <?php endif; ?>
    </script>

</body>

</html>
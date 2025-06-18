<?php
/**
 * Fichero de funciones compartidas para la aplicación de cambio.
 * Versión 2.0 - Adaptada para gestión de datos mediante JSON.
 */

define('CURRENCIES_FILE', 'currencies.json');
define('STOCK_FILE', 'stock.json');

/**
 * Carga todos los datos de las monedas desde el fichero JSON.
 * @return array Un array asociativo con los datos de todas las monedas.
 */
function get_all_currencies(): array
{
    if (!file_exists(CURRENCIES_FILE)) {
        return [];
    }
    $json_data = file_get_contents(CURRENCIES_FILE);
    return json_decode($json_data, true);
}

/**
 * Carga todo el stock desde el fichero JSON. Si no existe, lo crea con 1B de unidades.
 * @return array Un array con el stock de todas las monedas.
 */
function get_all_stock(): array
{
    if (!file_exists(STOCK_FILE)) {
        // Si el fichero de stock no existe, lo generamos a partir de currencies.json
        $currencies = get_all_currencies();
        $stock = [];
        foreach ($currencies as $code => $details) {
            foreach ($details['denominations'] as $denomination) {
                // Stock inicial de mil millones para cada una
                $stock[$code][(string) $denomination] = "1000000000";
            }
        }
        // Guardamos el fichero recién creado
        file_put_contents(STOCK_FILE, json_encode($stock, JSON_PRETTY_PRINT));
        return $stock;
    }

    $json_data = file_get_contents(STOCK_FILE);
    return json_decode($json_data, true);
}


/**
 * Escribe el array de stock completo en el fichero JSON.
 * Utiliza un bloqueo para evitar corrupción de datos.
 *
 * @param array $all_stock El array completo con el stock de todas las monedas.
 * @return bool True si la escritura fue exitosa.
 */
function write_all_stock(array $all_stock): bool
{
    $fp = fopen(STOCK_FILE, 'w');
    if (!$fp)
        return false;

    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($all_stock, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    } else {
        return false;
    }

    fclose($fp);
    return true;
}
<?php
/**
 * Parse wiki page and create html
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();


// Check if telegram token exists
$dotenv->required(['TELEGRAM_TOKEN', 'TELEGRAM_GROUP', 'TELEGRAM_FAULT']);


// Send message to Telegram
function send_message($text, $fault = false) {
    $message = [
        'chat_id' => getenv('TELEGRAM_GROUP'),
        'text' => $text,
        'disable_notification' => true,
        'parse_mode' => 'HTML'
    ];

    // Telegram bot api url
    $botapi = 'https://api.telegram.org/bot' . getenv('TELEGRAM_TOKEN') . '/sendMessage?';

    if ($fault === true) {
        $message['chat_id'] = getenv('TELEGRAM_FAULT');
    }

    @file_get_contents($botapi . http_build_query($message));
}


// Parse China table
function parse_china_table($html, $data) {
    $fields = $html->find('tr:last-child td');

    if (!preg_match('/total/i', $fields[0]->text())) {
        throw new Exception('China table markup error');
    }

    $info = [
        'region' => 'China',
        'cases' => $fields[1]->text(),
        'death' => $fields[2]->text()
    ];

    foreach ($info as &$field) {
        $field = str_replace(',', '', trim($field));
    }

    $data[] = $info;

    return $data;
}

// Parse non-China table
function parse_data_table($html, $data) {
    $rows = $html->find('tr');

    foreach (array_slice($rows, 1, -1) as $row) {
        $info = [
            'region' => $row->child(0)->text(),
            'cases' => $row->child(1)->text(),
            'death' => $row->child(2)->text()
        ];

        foreach ($info as &$field) {
            $field = str_replace(',', '', trim($field));
        }

        $data[] = $info;
    }

    return $data;
}


// Parse data from wiki
function parse_data($data = []) {
    $context = stream_context_create([
        'http'=> [
            'timeout' => 10,
            'user_agent' => 'Coronavirus fetch bot / https://coronavirus.zone'
        ]
    ]);

    $page = @file_get_contents('https://bnonews.com/index.php/2020/01/the-latest-coronavirus-cases/', false, $context);

    if ($page === false) {
        throw new Exception("Can't get news page");
    }

    $html = new DiDom\Document;
    $html->loadHtml($page);

    // Get all tables
    $tables = $html->find('.wp-block-table');

    foreach ($tables as $table) {
        $text = $table->text();

        // Collect data from China
        if (preg_match('/china/i', $text)) {
            $data = parse_china_table($table, $data);
        }

        // Collect Regions and Internationas
        if (preg_match('/regions|international/i', $text)) {
            $data = parse_data_table($table, $data);
        }
    }

    // Sort by cases
    $cases = array_column($data, 'cases');
    array_multisort($cases, SORT_DESC, $data);

    return $data;
}


// Compare and update item value
function compare_value($info, $current, $key) {
    if (!isset($current[$key])) {
        return $info[$key];
    }

    if ($info[$key] > $current[$key]) {
        return $info[$key] . '+';
    }

    if ($info[$key] < $current[$key]) {
        return $info[$key] . '-';
    }

    return $info[$key];
}


// Update data in channel
function update_channel($current, $parsed) {
    $rows = [];

    foreach ($parsed as $info) {
        $item = array_search($info['region'], array_column($current, 'region'));

        if (is_int($item)) {
            // Update cases field
            $info['cases'] = compare_value($info, $current[$item], 'cases');

            // Updated death field
            $info['death'] = compare_value($info, $current[$item], 'death');
        }

        $rows[] = "{$info['region']}\n{$info['cases']} / {$info['death']}";
    }

    $message = sprintf("<strong>Latest updates on the Wuhan coronavirus outbreak: </strong>\n<pre>%s</pre>", implode("\n", $rows));

    // Send update message to telegram
    send_message($message);
}

try {
    $storage = __DIR__ . '/build/data.json';

    // Parse data from wiki
    $parsed = parse_data();

    // Check buggy markup
    if (count($parsed) < 10) {
        throw new Exception("Something broken in news page markup");
    }

    // Get current data json
    $current = @file_get_contents($storage);

    if ($current !== false) {
        $backup = __DIR__ . '/backup/data-' . time() . '.json';

        // Backup current data
        file_put_contents($backup, $current);

        // Get JSON from file data
        $current = json_decode($current, JSON_OBJECT_AS_ARRAY);

        // Don't send if equal
        if ($current !== $parsed) {
            update_channel($current, $parsed);
        }
    }

    // Update data file
    file_put_contents($storage, json_encode($parsed));

} catch (Exception $e) {
    $message = sprintf('<strong>Error: </strong>%s', $e->getMessage());

    // Send fault message to telegram
    send_message($message, true);
}
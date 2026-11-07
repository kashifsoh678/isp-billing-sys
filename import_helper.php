<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Shared CSV import + template engine. One spec per page, no per-page parsing.
 *
 * spec = [
 *   'entity'  => 'Customer',                 // singular, shown to the user
 *   'table'   => 'customers',
 *   'perm'    => 'customers',                // can(perm,'a') imports, can(perm,'v') downloads
 *   'file'    => 'customers',                // template filename stem (defaults to table)
 *   'columns' => [ db_col => [
 *        'label'    => 'Full Name',          // CSV header (defaults to the column name)
 *        'type'     => 's|i|d|date|datetime|email|bool|enum|lookup',
 *        'required' => true,
 *        'default'  => 'active',             // used when the cell is blank
 *        'max'      => 100,                  // string length guard
 *        'values'   => ['a','b'],            // enum
 *        'lookup'   => ['table'=>'areas','match'=>['name','code'],'where'=>'is_deleted = 0'],
 *        'demo'     => 'Client 1',           // template's sample row
 *   ]],
 *   'static'  => ['created_by' => 4],        // same value on every inserted row
 *   'unique'  => ['phone'],                  // dedup against the table AND inside the file
 *   'init'    => function (mysqli $conn, array &$state) {},                       // once, before the rows
 *   'before'  => function (array &$row, mysqli $conn, array &$state): ?string {},  // per row, return an error to skip it
 *   'after'   => function (int $id, array $row, mysqli $conn, array &$state) {},   // per row, after insert
 * ]
 */

define('RS_IMPORT_MAX_ROWS', 5000);
define('RS_IMPORT_MAX_BYTES', 5 * 1024 * 1024);
define('RS_IMPORT_MAX_ERRORS', 50);

function rsImportOut(array $payload) { echo json_encode($payload); exit(); }

// header/value matcher — "Full Name", "full_name" and "FULLNAME" are the same thing
function rsImportKey($s) { return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s))); }

function rsImportLabel($col, $def) { return $def['label'] ?? ucwords(str_replace('_', ' ', $col)); }

// ── template: headers + one demo row ─────────────────────────────────────
function rsImportTemplate(array $spec) {
    if (!can($spec['perm'], 'v')) { http_response_code(403); exit('Not allowed'); }
    $labels = []; $demo = [];
    foreach ($spec['columns'] as $col => $def) {
        $labels[] = rsImportLabel($col, $def);
        $demo[]   = (string)($def['demo'] ?? '');
    }
    $name = ($spec['file'] ?? $spec['table']) . '_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");              // BOM so Excel reads UTF-8
    fputcsv($out, $labels);
    fputcsv($out, $demo);
    fclose($out);
    exit();
}

// ── one cell -> a value the column will accept, or an error string ────────
function rsImportCell($raw, $col, array $def, array $lookups) {
    $v = trim((string)$raw);
    $label = rsImportLabel($col, $def);
    $type  = $def['type'] ?? 's';

    if ($v === '') {
        if (!empty($def['required'])) return ['err' => "$label is required"];
        return ['val' => array_key_exists('default', $def) ? $def['default'] : null];
    }

    switch ($type) {
        case 'i':
            if (!preg_match('/^-?\d+$/', str_replace([',', ' '], '', $v))) return ['err' => "$label must be a whole number"];
            return ['val' => (int)str_replace([',', ' '], '', $v)];

        case 'd':
            $n = str_replace([',', ' '], '', $v);
            if (!is_numeric($n)) return ['err' => "$label must be a number"];
            return ['val' => round((float)$n, (int)($def['scale'] ?? 2))];

        case 'bool':
            $t = strtolower($v);
            if (in_array($t, ['1','yes','y','true','active','on'], true))  return ['val' => 1];
            if (in_array($t, ['0','no','n','false','inactive','off'], true)) return ['val' => 0];
            return ['err' => "$label must be Yes or No"];

        case 'date':
        case 'datetime':
            $ts = strtotime(str_replace('/', '-', $v));       // d-m-Y and Y-m-d both land
            if ($ts === false) return ['err' => "$label is not a valid date (use YYYY-MM-DD)"];
            return ['val' => date($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', $ts)];

        case 'email':
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return ['err' => "$label is not a valid email"];
            return ['val' => $v];

        case 'enum':
            foreach (($def['values'] ?? []) as $opt) {
                if (rsImportKey($opt) === rsImportKey($v)) return ['val' => $opt];
            }
            return ['err' => "$label must be one of: " . implode(', ', $def['values'] ?? [])];

        case 'lookup':
            $id = $lookups[$col][rsImportKey($v)] ?? null;
            if ($id === null) return ['err' => "$label \"$v\" was not found"];
            return ['val' => (int)$id];

        default:
            $max = (int)($def['max'] ?? 0);
            $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);   // mbstring isn't guaranteed
            if ($max > 0 && $len > $max) return ['err' => "$label is longer than $max characters"];
            return ['val' => $v];
    }
}

// ── the import itself ────────────────────────────────────────────────────
function rsImportRun(mysqli $conn, array $spec) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')  rsImportOut(['success' => false, 'message' => 'Invalid request method']);
    if (!can($spec['perm'], 'a'))               rsImportOut(['success' => false, 'message' => 'Permission denied']);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest')
        rsImportOut(['success' => false, 'message' => 'Invalid request']);

    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
        rsImportOut(['success' => false, 'message' => 'No file uploaded']);
    if ($f['size'] > RS_IMPORT_MAX_BYTES)
        rsImportOut(['success' => false, 'message' => 'File too large (max 5MB)']);
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv')
        rsImportOut(['success' => false, 'message' => 'Only .csv files are accepted']);

    $fh = fopen($f['tmp_name'], 'r');
    if (!$fh) rsImportOut(['success' => false, 'message' => 'Could not read the file']);

    // sniff the separator — Excel in a European locale writes semicolons
    $first  = (string)fgets($fh);
    rewind($fh);
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = key($counts);
    if (!$counts[$delim]) $delim = ',';

    $head = fgetcsv($fh, 0, $delim);
    if (!$head) { fclose($fh); rsImportOut(['success' => false, 'message' => 'The file is empty']); }
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);

    // csv position -> db column
    $idx = [];
    foreach ($head as $i => $h) {
        $k = rsImportKey($h);
        if ($k === '') continue;
        foreach ($spec['columns'] as $col => $def) {
            if (isset($idx[$col])) continue;
            if ($k === rsImportKey($col) || $k === rsImportKey(rsImportLabel($col, $def))) { $idx[$col] = $i; break; }
        }
    }
    $missing = [];
    foreach ($spec['columns'] as $col => $def)
        if (!empty($def['required']) && !isset($idx[$col])) $missing[] = rsImportLabel($col, $def);
    if ($missing) {
        fclose($fh);
        rsImportOut(['success' => false, 'message' => 'Missing column(s): ' . implode(', ', $missing) . '. Download the template and use its headers.']);
    }

    // one query per lookup table, then O(1) in memory
    $lookups = [];
    foreach ($spec['columns'] as $col => $def) {
        if (($def['type'] ?? '') !== 'lookup' || !isset($idx[$col])) continue;
        $lk = $def['lookup'];
        $cols = implode(',', array_map(fn($c) => "`$c`", $lk['match']));
        $where = !empty($lk['where']) ? ' WHERE ' . $lk['where'] : '';
        $res = $conn->query("SELECT id, $cols FROM `{$lk['table']}`$where");
        $map = [];
        while ($res && ($x = $res->fetch_assoc())) {
            foreach ($lk['match'] as $m) {
                $key = rsImportKey($x[$m] ?? '');
                if ($key !== '' && !isset($map[$key])) $map[$key] = (int)$x['id'];
            }
        }
        $lookups[$col] = $map;
    }

    // existing values for the unique columns, so a re-import can't double-add
    $seen = [];
    $softQ = $conn->query("SHOW COLUMNS FROM `{$spec['table']}` LIKE 'is_deleted'");
    $soft  = ($softQ && $softQ->num_rows) ? ' WHERE is_deleted = 0' : '';
    foreach (($spec['unique'] ?? []) as $u) {
        $seen[$u] = [];
        $res = $conn->query("SELECT `$u` v FROM `{$spec['table']}`$soft");
        while ($res && ($x = $res->fetch_assoc())) {
            $k = rsImportKey($x['v']);
            if ($k !== '') $seen[$u][$k] = true;
        }
    }

    // column meta — a NULL bound to a NOT NULL column would roll the whole batch back
    $meta = [];
    $mres = $conn->query("SHOW COLUMNS FROM `{$spec['table']}`");
    while ($mres && ($m = $mres->fetch_assoc())) $meta[$m['Field']] = $m;

    $state = [];
    if (isset($spec['init'])) ($spec['init'])($conn, $state);

    $cols   = array_keys($spec['columns']);
    $static = $spec['static'] ?? [];
    $rows = []; $errors = []; $skipped = 0; $line = 1;

    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $line++;
        if (!array_filter($r, fn($v) => trim((string)$v) !== '')) continue;   // blank line
        if (count($rows) >= RS_IMPORT_MAX_ROWS) {
            $errors[] = 'Stopped at ' . RS_IMPORT_MAX_ROWS . ' rows — split the file and import again.';
            break;
        }

        $row = []; $err = null;
        foreach ($spec['columns'] as $col => $def) {
            $raw = isset($idx[$col]) ? ($r[$idx[$col]] ?? '') : '';
            $out = rsImportCell($raw, $col, $def, $lookups);
            if (isset($out['err'])) { $err = $out['err']; break; }
            $val = $out['val'];
            if ($val === null && ($meta[$col]['Null'] ?? 'YES') === 'NO') {
                $type = $def['type'] ?? 's';
                if ($type === 'lookup') { $err = rsImportLabel($col, $def) . ' is required'; break; }
                $dbDef = $meta[$col]['Default'] ?? null;
                $val = $dbDef !== null ? $dbDef : (in_array($type, ['i', 'd', 'bool'], true) ? 0 : '');
            }
            $row[$col] = $val;
        }
        if ($err === null) {
            foreach (($spec['unique'] ?? []) as $u) {
                $k = rsImportKey($row[$u] ?? '');
                if ($k === '') continue;
                if (isset($seen[$u][$k])) { $err = rsImportLabel($u, $spec['columns'][$u] ?? []) . ' "' . $row[$u] . '" already exists'; break; }
            }
        }
        if ($err === null && isset($spec['before'])) $err = ($spec['before'])($row, $conn, $state);

        if ($err !== null) {
            $skipped++;
            if (count($errors) < RS_IMPORT_MAX_ERRORS) $errors[] = "Row $line: $err";
            continue;
        }
        foreach (($spec['unique'] ?? []) as $u) {
            $k = rsImportKey($row[$u] ?? '');
            if ($k !== '') $seen[$u][$k] = true;
        }
        $rows[] = array_merge($row, $static);
    }
    fclose($fh);

    if (!$rows) {
        rsImportOut(['success' => true, 'imported' => 0, 'skipped' => $skipped, 'errors' => $errors,
                     'message' => 'Nothing was imported — every row was skipped']);
    }

    // one prepared statement, one transaction.
    // every row must offer the same columns — a hook that injects a key on some rows
    // only would otherwise shift the binds on the rows that follow
    $insertCols = [];
    foreach ($rows as $r) foreach (array_keys($r) as $k) if (!in_array($k, $insertCols, true)) $insertCols[] = $k;
    foreach ($rows as &$r) foreach ($insertCols as $k) if (!array_key_exists($k, $r)) $r[$k] = null;
    unset($r);

    $types = '';
    foreach ($insertCols as $c) {
        $t = $spec['columns'][$c]['type'] ?? 's';
        $types .= ($t === 'i' || $t === 'lookup' || $t === 'bool') ? 'i' : ($t === 'd' ? 'd' : 's');
    }
    $sql = "INSERT INTO `{$spec['table']}` (`" . implode('`,`', $insertCols) . "`) VALUES ("
         . rtrim(str_repeat('?,', count($insertCols)), ',') . ")";

    $imported = 0;
    $conn->begin_transaction();
    try {
        $st = $conn->prepare($sql);
        foreach ($rows as $row) {
            $vals = []; $refs = [];
            foreach ($insertCols as $i => $c) { $vals[$i] = $row[$c]; $refs[$i] = &$vals[$i]; }
            $st->bind_param($types, ...$refs);
            $st->execute();
            $id = (int)$conn->insert_id;
            if (isset($spec['after'])) ($spec['after'])($id, $row, $conn, $state);
            $imported++;
            unset($vals, $refs);
        }
        $st->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("CSV import ({$spec['table']}): " . $e->getMessage());
        rsImportOut(['success' => false, 'message' => 'Import failed, nothing was saved: ' . $e->getMessage()]);
    }

    logActivity($_SESSION['user_id'] ?? null, $_SESSION['username'] ?? '', 'Import',
        "Imported $imported {$spec['entity']} record(s) from CSV" . ($skipped ? ", $skipped skipped" : ''));

    rsImportOut(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors,
                 'message' => "$imported imported" . ($skipped ? ", $skipped skipped" : '')]);
}

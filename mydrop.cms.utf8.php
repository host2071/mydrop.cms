<?php

const MDCMS_VERSION = '1.1';
const MDCMS_PASSWORD = '123qwe!@#';
const MDCMS_DOMAIN = 'industrial-craft2.ru';

session_start();

if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'didom' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'didom' . DIRECTORY_SEPARATOR . 'autoload.php';
}

if (isset($_POST['password']) && is_string($_POST['password']) && $_POST['password'] === MDCMS_PASSWORD) {
    $_SESSION['mdcms_accepted'] = true;
}

if (strlen(MDCMS_PASSWORD) > 0 && ((isset($_SESSION['mdcms_accepted']) && $_SESSION['mdcms_accepted'] !== true) || !isset($_SESSION['mdcms_accepted']))) {
    die('<!DOCTYPE html><html lang="en-EN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MyDrop CMS</title><link rel="stylesheet" href="https://bootswatch.com/3/yeti/bootstrap.min.css" crossorigin="anonymous" /><style>*{-webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box;}html,body{height:100%;}.wrap{min-height:100%;height:auto;}</style></head><body><div class="wrap"><div class="container"><div class="row"><div class="col-xs-12 col-sm-8 col-md-6 col-sm-offset-2 col-md-offset-3" style="margin-top:10%"><form method="post"><div class="input-group"><input type="text" name="password" class="form-control" placeholder="Password" autofocus required><span class="input-group-btn"><button class="btn btn-success" type="submit">Submit</button></span></div></form></div></div></div></div></body></html>');
}

$config = [
    'dirname' => dirname(__FILE__),
    'language' => loadLanguage('ru'),
    'source' => loadSourceDir(),
    'update' => false,
    'domain' => [
        'safeName' => preg_replace('/[^a-z0-9]/', '_', MDCMS_DOMAIN),
        'urls' => [],
        'tree' => [],
        'root' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'],
    ],
    'data' => null,
    'export_rules' => [],
    'export_preview_rowid' => 0,
];

$config['db'] = new PDO('sqlite:' . $config['source'] . '.mdcms_data' . DIRECTORY_SEPARATOR . 'structure.db');
$query = $config['db']->prepare('CREATE TABLE IF NOT EXISTS structure (url TEXT, filename TEXT);');
$query->execute();
$query = $config['db']->prepare('CREATE TABLE IF NOT EXISTS setting (version TEXT, version_checked INTEGER);');
$query->execute();

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'editor.content') {
        $data = getUrlData($_POST['rowid']);
        if (isset($data['filename'])) {
            $filename = getHtmlPathByFilename($data['filename']);
            if (file_exists($filename)) {
                file_put_contents($filename, $_POST['content']);
            }
        }
        echo '[]';
        exit;
    } else if ($_POST['action'] === 'replacer.do') {
        $_POST['replacer_search'] = normalizeLineBreaks($_POST['replacer_search']);
        $_POST['replacer_text'] = normalizeLineBreaks($_POST['replacer_text']);
        $replacerMode = isset($_POST['replacer_mode']) ? $_POST['replacer_mode'] : 'text';
        $query = $config['db']->prepare('SELECT rowid, * FROM structure ORDER BY url');
        $query->execute();
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $filename = getHtmlPathByFilename($row['filename']);
            if (file_exists($filename)) {
                if ($replacerMode === 'css_remove') {
                    removeNodesByCssSelector($filename, $_POST['replacer_search']);
                } else {
                    file_put_contents($filename, str_replace($_POST['replacer_search'], $_POST['replacer_text'], normalizeLineBreaks(file_get_contents($filename))));
                }
            }
        }
        echo '[]';
        exit;
    } else if ($_POST['action'] === 'export.rules.save') {
        $rules = normalizeExportRules(isset($_POST['rules']) ? $_POST['rules'] : []);
        saveExportRules($rules);
        exportJson([
            'success' => true,
            'message' => 'Правила экспорта сохранены',
            'rules' => $rules,
        ]);
    } else if ($_POST['action'] === 'export.preview') {
        $rules = normalizeExportRules(isset($_POST['rules']) ? $_POST['rules'] : []);
        if (count($rules) === 0) {
            $rules = loadExportRules();
        }
        $row = getUrlData(isset($_POST['rowid']) ? $_POST['rowid'] : 0);
        if (!$row) {
            exportJson([
                'success' => false,
                'message' => 'Не выбрана тестовая страница',
                'rules' => $rules,
            ]);
        }
        exportJson(previewExportRules($rules, $row));
    } else if ($_POST['action'] === 'export.csv') {
        $rules = normalizeExportRules(isset($_POST['rules']) ? $_POST['rules'] : []);
        if (count($rules) === 0) {
            exportJson([
                'success' => false,
                'message' => 'Добавьте хотя бы одно правило экспорта',
            ]);
        }
        saveExportRules($rules);
        exportJson(generateExportCsv($rules));
    } else if ($_POST['action'] === 'logout') {
        unset($_SESSION['mdcms_accepted']);
        echo '[]';
        exit;
    }
}

$query = $config['db']->prepare('SELECT COUNT(*) FROM structure');
$query->execute();
if ($query->fetchColumn() === 0) {
    if (!file_exists($config['dirname'] . DIRECTORY_SEPARATOR . MDCMS_DOMAIN . '.csv')) {
        die(t('error_not_found_csv'));
    }
    $handler = fopen($config['dirname'] . DIRECTORY_SEPARATOR . MDCMS_DOMAIN . '.csv', 'r');
    while (($buffer = fgets($handler)) !== false) {
        if (!preg_match('/^' . MDCMS_DOMAIN . '([^;]+);' . MDCMS_DOMAIN . '\/[a-f0-9]{2}\/[a-f0-9]{2}\/([a-f0-9]{32})\.html$/', trim($buffer), $match)) {
            continue;
        }
        $query = $config['db']->prepare('INSERT INTO structure (url, filename) VALUES (:url, :filename)');
        $query->execute([
            'url' => $match[1],
            'filename' => $match[2],
        ]);
    }
    fclose($handler);
}

$urls = [];
$query = $config['db']->prepare('SELECT rowid, * FROM structure ORDER BY url');
$query->execute();
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $config['domain']['urls'][$row['rowid']] = $row['url'];
    $explode = explode('/', $row['url']);
    $count = count($explode);
    $keys = [];
    for ($i = 1; $i < $count; ++$i) {
        if ($i + 1 < $count) {
            if ($explode[$i] !== '') {
                $keys[] = $explode[$i];
            }
        } else {
            if (count($keys) > 0) {
                $tmp = [$row['rowid']];
                foreach (array_reverse($keys) as $key) {
                    $tmp = ['s' . $key => $tmp];
                }
                $urls[] = $tmp;
            } else {
                $urls[] = [$row['rowid']];
            }
        }
    }
}
$config['domain']['tree'] = count($urls) > 0 ? call_user_func_array('array_merge_recursive', $urls) : [];
unset($urls);

if (isset($_GET['download']) && $_GET['download'] === 'export.csv' && isset($_GET['file'])) {
    serveExportFile($_GET['file']);
}

$query = $config['db']->prepare('SELECT COUNT(*) FROM setting');
$query->execute();
if ($query->fetchColumn() === '0') {
    $data = getVersion();
    $query = $config['db']->prepare('INSERT INTO setting (version, version_checked) VALUES (:version, :version_checked)');
    $query->execute([
        'version' => $data === false ? MDCMS_VERSION : $data,
        'version_checked' => time(),
    ]);
} else {
    $query = $config['db']->prepare('SELECT rowid, * FROM setting LIMIT 1');
    $query->execute();
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        if (time() - $row['version_checked'] > 86400) {
            $data = getVersion();
            if ($data === false) {
                $query = $config['db']->prepare('UPDATE setting SET version_checked = :version_checked WHERE rowid = :rowid');
                $query->execute([
                    'version_checked' => time(),
                    'rowid' => $row['rowid'],
                ]);
            } else {
                $query = $config['db']->prepare('UPDATE setting SET version = :version, version_checked = :version_checked WHERE rowid = :rowid');
                $query->execute([
                    'version' => $data,
                    'version_checked' => time(),
                    'rowid' => $row['rowid'],
                ]);
                $row['version'] = $data;
            }
        }
        if ($row['version'] !== MDCMS_VERSION) {
            $config['update'] = true;
        }
    }
}

if (isset($_GET['rowid']) && $_GET['do'] == 'edit') {
    $config['data'] = getUrlData($_GET['rowid']);
    $config['data']['filepath'] = getHtmlRelativePath($config['data']['filename']);
    $config['data']['content'] = file_get_contents($config['source'] . $config['data']['filepath']);
}

$config['export_rules'] = loadExportRules();
$config['export_preview_rowid'] = getFirstRowId();

function t($key) {
    return isset($GLOBALS['config']['language'][$key]) ? $GLOBALS['config']['language'][$key] : $key;
}

function loadLanguage($key) {
    $languages = [
        'ru' => [
            'footer' => 'сервис для работы с освобождающимися доменами',
            'footer_support' => 'Техническая поддержка — info@mydrop.io',
            'error_folder' => 'Невозможно создать папку .mdcms_data для хранение файлов',
            'error_not_folder' => 'Файл .mdcms_data должен быть папкой',
            'error_not_found_csv' => 'Файл CSV не найден',
            'search' => 'Искомый текст',
            'replace' => 'На что меняем',
            'do' => 'Выполнить',
            'save' => 'Сохранить',
            'WYSIWYG' => 'Редактор WYSIWYG',
            'CODE' => 'Редактор HTML',
            'editor' => 'Редактор',
            'replacer' => 'Замена текста',
            'editor_header' => 'Выберите URL который хотите отредактировать',
            'replacer_header' => 'Массовая замена текста во всех HTML-файлах',
            'message_saved' => 'Изменение успешно сохранено',
            'need_update' => 'Доступна более новая версия MyDrop CMS — скачать.',
        ],
        'en' => [],
    ];
    return isset($languages[$key]) ? $languages[$key] : [];
}

function loadSourceDir() {
    $dir = dirname(__FILE__);
    if (!file_exists($dir . DIRECTORY_SEPARATOR . '.mdcms_data') && !mkdir($dir . DIRECTORY_SEPARATOR . '.mdcms_data', 0770, true)) {
        die(t('error_folder'));
    }
    if (!is_dir($dir . DIRECTORY_SEPARATOR . '.mdcms_data')) {
        die(t('error_not_folder'));
    }
    return $dir . DIRECTORY_SEPARATOR;
}

function getUrlData($id) {
    $query = $GLOBALS['config']['db']->prepare('SELECT rowid, * FROM structure WHERE rowid = :id');
    $query->execute(['id' => $id]);
    return $query->fetch(PDO::FETCH_ASSOC);
}

function createTree($data) {
    echo '<ul class="d-none">';
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            echo '<li data-jstree=\'{"icon":"glyphicon glyphicon-folder-open","disabled":false,"type":1}\'>/' . htmlspecialchars(rawurldecode(substr($key, 1)), ENT_IGNORE);
            createTree($value);
            echo '</li>';
        } else {
            echo '<li data-jstree=\'{"id":' . $value . ',"icon":"glyphicon glyphicon-file","type":2}\'>' . htmlspecialchars(rawurldecode($GLOBALS['config']['domain']['urls'][$value]), ENT_IGNORE) . '</li>';
        }
    }
    echo '</ul>';
}

function getVersion() {
    $json = file_get_contents('https://mydrop.io/cms.json', false, stream_context_create(['http' => ['timeout' => 5]]));
    if ($json === false) {
        return false;
    }
    $data = json_decode($json, true);
    if ($data === false || !isset($data['version']) || !preg_match('/\d+\.\d+/', $data['version'])) {
        return false;
    }
    return $data['version'];
}

function exportJson($payload) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonForScript($payload) {
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function normalizeLineBreaks($value) {
    return str_replace(["\r\n", "\r"], "\n", $value);
}

function removeNodesByCssSelector($filename, $selector) {
    $selector = trim($selector);
    if ($selector === '' || !class_exists('DiDom\\Document')) {
        return;
    }

    try {
        $document = new \DiDom\Document($filename, true, 'UTF-8', \DiDom\Document::TYPE_HTML);
        $nodes = $document->find($selector, \DiDom\Query::TYPE_CSS);
        foreach ($nodes as $node) {
            $node->remove();
        }
        file_put_contents($filename, $document->html());
    } catch (Exception $e) {
        return;
    }
}

function getHtmlRelativePath($filename) {
    return substr($filename, 0, 2) . DIRECTORY_SEPARATOR . substr($filename, 2, 2) . DIRECTORY_SEPARATOR . $filename . '.html';
}

function getHtmlPathByFilename($filename) {
    return $GLOBALS['config']['source'] . getHtmlRelativePath($filename);
}

function getExportRulesPath() {
    return $GLOBALS['config']['source'] . '.mdcms_data' . DIRECTORY_SEPARATOR . 'export_rules.json';
}

function getDefaultExportRules() {
    return [
        ['csv_field' => 'title', 'selector' => 'title', 'sample_value' => ''],
        ['csv_field' => 'desc', 'selector' => 'meta[name="description"]@content', 'sample_value' => ''],
        ['csv_field' => 'slug', 'selector' => '', 'sample_value' => ''],
        ['csv_field' => 'h1', 'selector' => 'h1', 'sample_value' => ''],
        ['csv_field' => 'description', 'selector' => '.content', 'sample_value' => ''],
    ];
}

function normalizeExportRules($rules) {
    if (!is_array($rules)) {
        return [];
    }
    $result = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $csvField = trim(isset($rule['csv_field']) ? $rule['csv_field'] : '');
        $selector = trim(isset($rule['selector']) ? $rule['selector'] : '');
        $sampleValue = isset($rule['sample_value']) ? $rule['sample_value'] : '';
        if ($csvField === '') {
            continue;
        }
        if ($csvField === 'slug') {
            $selector = '';
        }
        $result[] = [
            'csv_field' => $csvField,
            'selector' => $selector,
            'sample_value' => $sampleValue,
        ];
    }
    return $result;
}

function loadExportRules() {
    $path = getExportRulesPath();
    if (!file_exists($path)) {
        $rules = getDefaultExportRules();
        saveExportRules($rules);
        return $rules;
    }
    $rules = json_decode(file_get_contents($path), true);
    $rules = normalizeExportRules($rules);
    if (count($rules) === 0) {
        $rules = getDefaultExportRules();
        saveExportRules($rules);
    }
    return $rules;
}

function saveExportRules($rules) {
    file_put_contents(getExportRulesPath(), json_encode(array_values($rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function getFirstRowId() {
    foreach ($GLOBALS['config']['domain']['urls'] as $rowId => $url) {
        return (int) $rowId;
    }
    return 0;
}

function createExportPreviewOptions($selectedId) {
    $options = [];
    foreach ($GLOBALS['config']['domain']['urls'] as $key => $value) {
        $options[] = '<option value="' . (int) $key . '"' . ((int) $selectedId === (int) $key ? ' selected' : '') . '>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return implode('', $options);
}

function getRowDocument($row) {
    if (!class_exists('DiDom\\Document')) {
        return false;
    }
    $path = getHtmlPathByFilename($row['filename']);
    if (!file_exists($path)) {
        return false;
    }
    try {
        return new \DiDom\Document($path, true, 'UTF-8', \DiDom\Document::TYPE_HTML);
    } catch (Exception $e) {
        return false;
    }
}

function extractSlugValue($url) {
    $segments = array_values(array_filter(explode('/', trim($url)), 'strlen'));
    return count($segments) > 0 ? end($segments) : '';
}

function extractRuleValue($rule, $row, $document, &$warnings) {
    $field = isset($rule['csv_field']) ? $rule['csv_field'] : '';
    $selector = isset($rule['selector']) ? trim($rule['selector']) : '';
    if ($field === 'slug') {
        return extractSlugValue(isset($row['url']) ? $row['url'] : '');
    }
    if ($document === false) {
        $warnings[] = 'Часть HTML-файлов не найдена или не разобрана';
        return '';
    }
    if ($selector === '') {
        return '';
    }
    $attribute = '';
    if (strpos($selector, '@') !== false) {
        $parts = explode('@', $selector, 2);
        $selector = trim($parts[0]);
        $attribute = trim($parts[1]);
    }
    try {
        $element = $document->first($selector, \DiDom\Query::TYPE_CSS);
    } catch (Exception $e) {
        $warnings[] = 'Некорректный селектор: ' . (isset($rule['selector']) ? $rule['selector'] : '');
        return '';
    }
    if (!$element) {
        return '';
    }
    if ($attribute !== '') {
        return trim((string) $element->getAttribute($attribute, ''));
    }
    if ($field === 'description') {
        return trim(normalizeLineBreaks($element->innerHtml()));
    }
    return trim(normalizeLineBreaks($element->text()));
}

function previewExportRules($rules, $row) {
    $document = getRowDocument($row);
    $warnings = [];
    foreach ($rules as $index => $rule) {
        $rules[$index]['sample_value'] = extractRuleValue($rule, $row, $document, $warnings);
    }
    return [
        'success' => true,
        'message' => count($warnings) > 0 ? implode('. ', array_unique($warnings)) : 'Тестовые данные обновлены',
        'warnings' => array_values(array_unique($warnings)),
        'rules' => $rules,
    ];
}

function generateExportCsv($rules) {
    $filename = 'export-' . date('Ymd-His') . '.csv';
    $path = $GLOBALS['config']['source'] . '.mdcms_data' . DIRECTORY_SEPARATOR . $filename;
    $handle = fopen($path, 'w');
    if ($handle === false) {
        return ['success' => false, 'message' => 'Не удалось создать CSV-файл'];
    }
    fwrite($handle, chr(239) . chr(187) . chr(191));
    fputcsv($handle, array_column($rules, 'csv_field'), ';');
    $query = $GLOBALS['config']['db']->prepare('SELECT rowid, * FROM structure ORDER BY url');
    $query->execute();
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $document = getRowDocument($row);
        $warnings = [];
        $line = [];
        foreach ($rules as $rule) {
            $line[] = extractRuleValue($rule, $row, $document, $warnings);
        }
        fputcsv($handle, $line, ';');
    }
    fclose($handle);
    return [
        'success' => true,
        'message' => 'CSV сформирован',
        'download_url' => htmlspecialchars($_SERVER['SCRIPT_NAME'], ENT_QUOTES, 'UTF-8') . '?download=export.csv&file=' . rawurlencode($filename),
    ];
}

function serveExportFile($file) {
    $safeName = basename($file);
    if ($safeName === '' || !preg_match('/^export-\d{8}-\d{6}\.csv$/', $safeName)) {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
    $path = $GLOBALS['config']['source'] . '.mdcms_data' . DIRECTORY_SEPARATOR . $safeName;
    if (!file_exists($path)) {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru-RU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow" />
    <title>MyDrop CMS</title>
    <link rel="stylesheet" href="https://bootswatch.com/3/yeti/bootstrap.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.3.7/themes/default/style.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.css" crossorigin="anonymous"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous"/>
    <link rel="shortcut icon" href="https://mydrop.io/favicon.ico" type="image/x-icon">
    <style>
    *{-webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box;}
    html,body{height:100%;font-size:16px;line-height:1.4;color:#222222;}
    label,.control-label{font-size:15px;}
    .navbar{border:none;font-size:14px;font-weight:300;border-radius:0;min-height:45px;margin-bottom:21px;}
    .navbar-default{background-color:#333333;border-color:#222222;}
    .container>.navbar-header{margin-right:0;margin-left:0;}
    .wrap{min-height:100%;height:auto;margin:0 auto -85px;padding:0 0 85px;}
    .wrap>.container,.wrap>.container-fluid{padding:60px 15px 20px;}
    .footer{display:block;height:85px;background-color:#eee;border-top:1px solid #ddd;padding:15px 0;}
    .form-horizontal .control-label{text-align:left;font-weight:normal;}
    .jstree-default a{white-space:normal !important;height:auto;}
    .jstree-anchor{height:auto !important;font-size:0.8em;}
    .jstree-leaf a{height:auto !important;}
    .jstree-default a.jstree-search{color:inherit;}
    .jstree-default-small .jstree-icon:empty{line-height:14px;margin-left:-5px;}
    .jstree-default-small>.jstree-no-dots .jstree-open>.jstree-ocl{background-position:-39px -6px;}
    .CodeMirror{box-sizing:border-box;margin:0;font:inherit;font-family:inherit;display:block;width:100%;padding:0;font-size:12px;line-height:1.42857143;color:#555;background-color:#fff;background-image:none;border:1px solid #ccc;border-radius:4px;box-shadow:inset 0 1px 1px rgba(0,0,0,.075);transition:border-color ease-in-out .15s, box-shadow ease-in-out .15s;font-family:monospace;}
    .CodeMirror-focused{border-color:#66afe9;outline:0;box-shadow:inset 0 1px 1px rgba(0,0,0,.075),0 0 8px rgba(102,175,233,.6);}
    #textarea_editor_html{min-height:400px;display:none;white-space:pre-wrap;word-wrap:break-word;}
    .btn.btn-primary[disabled]{background-color:#337ab7;}
    .glyphicon-folder-open{color:#edc237;}
    .glyphicon-file{color:#797d7f;}
    .table-export td,.table-export th{vertical-align:middle !important;}
    .table-export textarea.form-control{min-height:120px;resize:vertical;font-family:monospace;}
    .export-download{display:none;margin-top:10px;}
    .export-help{color:#666;margin-bottom:15px;}
    </style>
</head>
<body>
<div class="wrap">
    <nav id="w3" class="navbar-default navbar-fixed-top navbar">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#w3-collapse"><span class="sr-only">Toggle navigation</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span></button>
                <span class="navbar-brand" href="/">MyDrop CMS</span>
            </div>
            <div id="w3-collapse" class="collapse navbar-collapse">
                <ul id="w4" class="navbar-nav navbar-right nav">
                    <li><a href="https://mydrop.io/domains" target="_blank" rel="noopener noreferrer"><i class="fa fa-table"></i> Список доменов в MyDrop.io</a></li>
                    <li><a href="<?=$config['domain']['root']?>" target="_blank" rel="noopener noreferrer"><span class="glyphicon glyphicon-new-window" aria-hidden="true"></span> Перейти на сайт <?=$_SERVER['HTTP_HOST']?></a></li>
                    <li><a href="#" onclick="logout(); return false;"><span class="glyphicon glyphicon-off" aria-hidden="true"></span> Выйти</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <?php if($config['update']){?><div class="alert alert-warning" role="alert"><?=t('need_update')?></div><?php }?>
                <div>
                    <ul class="nav nav-tabs" role="tablist">
                        <li role="presentation" class="active"><a href="#editor" aria-controls="editor" role="tab" data-toggle="tab"><?=t('editor')?></a></li>
                        <li role="presentation"><a href="#replacer" aria-controls="replacer" role="tab" data-toggle="tab"><?=t('replacer')?></a></li>
                        <li role="presentation"><a href="#export" aria-controls="export" role="tab" data-toggle="tab">Экспорт CSV</a></li>
                    </ul>
                    <div class="tab-content" style="margin-top:1%">
                        <div role="tabpanel" class="tab-pane active" id="editor">
                            <div class="row">
                                <div class="col-sm-4 col-md-3">
                                    <div class="panel panel-default">
                                        <div class="panel-body">
                                            <div id="jstree_loading_alert"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span> Loading URL list ...</div>
                                            <div id="jstree_<?=$config['domain']['safeName']?>" data-domain-converted="<?=$config['domain']['safeName']?>" style="display:none;"><?php createTree($config['domain']['tree']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-8 col-md-9">
                                    <div class="panel panel-default">
                                        <div class="panel-heading"><?=t('editor_header')?></div>
                                        <div class="panel-body">
                                            <form class="form-horizontal">
                                                <div class="form-group">
                                                    <label class="col-sm-2 col-md-1 control-label">URL: </label>
                                                    <div class="col-sm-10 col-md-11">
                                                        <div class="input-group">
                                                            <input id="editor_url" type="text" class="form-control" value="<?=isset($config['data']['url']) ? $config['data']['url'] : ''?>" readonly="readonly" />
                                                            <div class="input-group-btn">
                                                                <a class="btn btn-default" onclick="copyLink(); return false;"><i class="glyphicon glyphicon-link" aria-hidden="true"></i></a>
                                                                <a class="btn btn-default" href="<?= $config['domain']['root'] . (isset($config['data']['url']) ? $config['data']['url'] : ''); ?>" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-new-window" aria-hidden="true"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group">
                                                    <label class="col-sm-2 col-md-1 control-label">Файл: </label>
                                                    <div class="col-sm-10 col-md-11">
                                                        <input type="text" class="form-control" value="<?=isset($config['data']['filepath']) ? $config['data']['filepath'] : ''?>" readonly="readonly" />
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <ul class="nav nav-tabs" role="tablist">
                                        <li role="presentation" class="active"><a href="#editor_html" aria-controls="editor_html" role="tab" data-toggle="tab"><?=t('WYSIWYG')?></a></li>
                                        <li role="presentation"><a id="editor_code-tab" href="#editor_code" aria-controls="editor_code" role="tab" data-toggle="tab"><?=t('CODE')?></a></li>
                                    </ul>
                                    <div class="alert alert-success text-center" id="savedAlert" role="alert" style="display:none;margin-top:1%;"><?=t('message_saved')?></div>
                                    <div class="tab-content" style="margin-top:1%">
                                        <div role="tabpanel" class="tab-pane active" id="editor_html">
                                            <form id="formHtml" action="" method="post" class="form-horizontal" onsubmit="ajaxSaveFile('formHtml'); return false;" autocomplete="off">
                                                <input type="hidden" name="action" value="editor.content"/>
                                                <input type="hidden" name="rowid" value="<?=$config['data']['rowid']?>"/>
                                                <div class="form-group"><div class="col-md-12"><textarea id="textarea_editor_html" name="content"><?=htmlspecialchars($config['data']['content']);?></textarea></div></div>
                                                <div class="form-group"><div class="col-md-12"><button type="submit" class="btn btn-primary"><?=t('save')?></button></div></div>
                                            </form>
                                        </div>
                                        <div role="tabpanel" class="tab-pane" id="editor_code">
                                            <form id="formCode" action="" method="post" class="form-horizontal" onsubmit="ajaxSaveFile('formCode'); return false;" autocomplete="off">
                                                <input type="hidden" name="action" value="editor.content"/>
                                                <input type="hidden" name="rowid" value="<?=$config['data']['rowid']?>"/>
                                                <div class="form-group"><div class="col-md-12"><textarea id="textarea_editor_code" name="content"><?=htmlspecialchars($config['data']['content']);?></textarea></div></div>
                                                <div class="form-group"><div class="col-md-12"><button type="submit" class="btn btn-primary"><?=t('save')?></button></div></div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane" id="replacer">
                            <div class="alert alert-success text-center" id="formReplacerAlert" role="alert" style="display:none;margin-top:1%;"><?=t('message_saved')?></div>
                            <div class="panel panel-default">
                                <div class="panel-heading"><?=t('replacer_header')?></div>
                                <div class="panel-body">
                                    <form id="formReplacer" action="" method="post" class="form-horizontal" onsubmit="ajaxReplacer(); return false;">
                                        <input type="hidden" name="action" value="replacer.do"/>
                                        <div class="form-group">
                                            <label class="col-sm-2 col-md-1 control-label">Режим: </label>
                                            <div class="col-sm-10 col-md-11">
                                                <select class="form-control" name="replacer_mode">
                                                    <option value="text">Простая замена текста</option>
                                                    <option value="css_remove">Удалить по CSS-селектору</option>
                                                </select>
                                                <p class="help-block">Для удаления блока по селектору укажите в поле поиска, например, <code>.social_share.clearfix</code>. Поле замены в этом режиме игнорируется.</p>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-sm-2 col-md-1 control-label"><?=t('search')?>: </label>
                                            <div class="col-sm-10 col-md-11"><textarea class="form-control" name="replacer_search" rows="4" style="resize:none;"></textarea></div>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-sm-2 col-md-1 control-label"><?=t('replace')?>: </label>
                                            <div class="col-sm-10 col-md-11"><textarea class="form-control" name="replacer_text" rows="4" style="resize:none;"></textarea></div>
                                        </div>
                                        <div class="form-group"><div class="col-md-12"><span class="pull-right"><button type="submit" class="btn btn-primary form-control"><?=t('do')?></button></span></div></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane" id="export">
                            <div id="exportAlert" class="alert" role="alert" style="display:none;"></div>
                            <div class="panel panel-default">
                                <div class="panel-heading">Экспорт CSV</div>
                                <div class="panel-body">
                                    <p class="export-help">Во второй колонке используйте CSS-селектор. Для чтения атрибута добавляйте суффикс <code>@content</code>, например <code>meta[name="description"]@content</code>.</p>
                                    <form id="formExport" class="form-horizontal" onsubmit="return false;" autocomplete="off">
                                        <div class="form-group">
                                            <label class="col-sm-2 col-md-2 control-label">Тестовая страница:</label>
                                            <div class="col-sm-10 col-md-6"><select class="form-control" name="rowid" id="export_rowid"><?=createExportPreviewOptions($config['export_preview_rowid'])?></select></div>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-export">
                                                <thead>
                                                    <tr>
                                                        <th style="width:20%;">Поле CSV</th>
                                                        <th style="width:35%;">Теги / селектор</th>
                                                        <th>Тестовые данные</th>
                                                        <th style="width:70px;">#</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="exportRulesBody"></tbody>
                                            </table>
                                        </div>
                                        <div class="form-group">
                                            <div class="col-md-12">
                                                <button type="button" class="btn btn-default" onclick="addExportRule();">Добавить правило</button>
                                                <button type="button" class="btn btn-primary" onclick="saveExportRules();">Сохранить правила</button>
                                                <button type="button" class="btn btn-info" onclick="previewExportRules();">Проверить</button>
                                                <button type="button" class="btn btn-success" onclick="exportCsv();">Экспортировать CSV</button>
                                            </div>
                                        </div>
                                        <div class="export-download" id="exportDownloadWrap"><a id="exportDownloadLink" class="btn btn-success" href="#" target="_blank" rel="noopener noreferrer">Скачать готовый CSV</a></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<footer class="footer"><div class="container"><p class="text-center">&copy; 2016-2019 <a href="https://mydrop.io" target="_blank" rel="noopener noreferrer">MyDrop.io</a> — <?=t('footer')?></p><p class="text-center"><?=t('footer_support')?></p></div></footer>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.3.7/jstree.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/addon/edit/matchbrackets.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/addon/edit/matchtags.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/css/css.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/htmlmixed/htmlmixed.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/javascript/javascript.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/xml/xml.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/4.9.4/tinymce.min.js" crossorigin="anonymous"></script>
<script>
var exportRules = <?=jsonForScript($config['export_rules'])?>;
$(document).ready(function () {
    $('#editor_code-tab').on('shown.bs.tab', function () {
        editor.refresh();
    });
    renderExportRules(exportRules);
});

function copyLink() {
    var copyText = document.createElement('textarea');
    copyText.value = '<?= $config['domain']['root']; ?>' + document.getElementById('editor_url').value;
    copyText.setAttribute('readonly', '');
    copyText.style = {position: 'absolute', left: '-9999px'};
    document.body.appendChild(copyText);
    copyText.select();
    document.execCommand('copy');
    document.body.removeChild(copyText);
}

function ajaxSaveFile(form) {
    $(':input[type="submit"]').attr('disabled', 'disabled');
    if (form == 'formCode') {
        editor.save();
    }
    if (form == 'formHtml') {
        tinymce.triggerSave();
    }
    $.post({
        cache: false,
        url: "<?=$_SERVER['REQUEST_URI']?>",
        data: $('#' + form).serialize(),
        success: function () {
            if (form == 'formHtml') {
                editor.setValue($('#textarea_editor_html').val());
            }
            if (form == 'formCode') {
                tinymce.editors[0].setContent($('#textarea_editor_code').val());
                tinymce.editors[0].theme.resizeTo('100%', 500);
                tinymce.editors[0].focus();
            }
            $('html, body').animate({ scrollTop: 0 }, 'fast');
            $('#savedAlert').show('fast').delay(2000).hide('fast');
            $(':input[type="submit"]').removeAttr('disabled');
        }
    });
    return false;
}

function ajaxReplacer() {
    $(':input[type="submit"]').attr('disabled', 'disabled');
    $.post({
        cache: false,
        url: "<?=$_SERVER['REQUEST_URI']?>",
        data: $('#formReplacer').serialize(),
        success: function () {
            $('html, body').animate({ scrollTop: 0 }, 'fast');
            $('#formReplacerAlert').show('fast').delay(2000).hide('fast');
            $(':input[type="submit"]').removeAttr('disabled');
        }
    });
    return false;
}

function renderExportRules(rules) {
    exportRules = $.isArray(rules) ? rules : [];
    if (exportRules.length === 0) {
        exportRules.push({csv_field:'', selector:'', sample_value:''});
    }
    var rows = '';
    for (var i = 0; i < exportRules.length; i++) {
        var isDescription = (exportRules[i].csv_field || '').toLowerCase() === 'description';
        var sampleField = isDescription
            ? '<textarea class="form-control" name="rules[' + i + '][sample_value]">' + escapeHtml(exportRules[i].sample_value || '') + '</textarea>'
            : '<input type="text" class="form-control" name="rules[' + i + '][sample_value]" value="' + escapeHtml(exportRules[i].sample_value || '') + '">';
        rows += '<tr>'
            + '<td><input type="text" class="form-control" name="rules[' + i + '][csv_field]" value="' + escapeHtml(exportRules[i].csv_field || '') + '"></td>'
            + '<td><input type="text" class="form-control" name="rules[' + i + '][selector]" value="' + escapeHtml(exportRules[i].selector || '') + '"></td>'
            + '<td>' + sampleField + '</td>'
            + '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeExportRule(' + i + ');"><span class="glyphicon glyphicon-trash"></span></button></td>'
            + '</tr>';
    }
    $('#exportRulesBody').html(rows);
}

function addExportRule() {
    exportRules.push({csv_field:'', selector:'', sample_value:''});
    renderExportRules(exportRules);
}

function removeExportRule(index) {
    exportRules.splice(index, 1);
    renderExportRules(exportRules);
}

function saveExportRules() {
    $.post({
        cache: false,
        url: "<?=$_SERVER['REQUEST_URI']?>",
        dataType: 'json',
        data: $('#formExport').serialize() + '&action=export.rules.save',
        success: function (response) {
            renderExportRules(response.rules || []);
            showExportAlert(response.success ? 'success' : 'danger', response.message || '');
        }
    });
}

function previewExportRules() {
    $.post({
        cache: false,
        url: "<?=$_SERVER['REQUEST_URI']?>",
        dataType: 'json',
        data: $('#formExport').serialize() + '&action=export.preview',
        success: function (response) {
            renderExportRules(response.rules || []);
            showExportAlert(response.warnings && response.warnings.length ? 'warning' : (response.success ? 'success' : 'danger'), response.message || '');
        }
    });
}

function exportCsv() {
    $.post({
        cache: false,
        url: "<?=$_SERVER['REQUEST_URI']?>",
        dataType: 'json',
        data: $('#formExport').serialize() + '&action=export.csv',
        success: function (response) {
            showExportAlert(response.success ? 'success' : 'danger', response.message || '');
            if (response.success && response.download_url) {
                $('#exportDownloadLink').attr('href', response.download_url);
                $('#exportDownloadWrap').show();
            }
        }
    });
}

function showExportAlert(type, message) {
    $('#exportAlert').removeClass('alert-success alert-danger alert-warning alert-info').addClass('alert-' + type).text(message).show();
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

$('#jstree_<?=$config['domain']['safeName']?>').bind('loaded.jstree', function(e, data) {
    $('#jstree_loading_alert').css('display', 'none');
    $('#jstree_<?=$config['domain']['safeName']?>').css('display', 'block');
}).jstree({
    "plugins": ["wholerow", "search", "sort", "state"],
    "search": {"case_sensitive": false, "show_only_matches": true},
    "core": {"themes": {"variant": "small"}, "multiple": false},
    "state": {"key": "domainname"},
    "sort": function (a, b) {
        a1 = this.get_node(a);
        b1 = this.get_node(b);
        if (a1.data.jstree.type == b1.data.jstree.type) {
            return (a1.text > b1.text) ? 1 : -1;
        } else {
            return (a1.data.jstree.type > b1.data.jstree.type) ? 1 : -1;
        }
    }
});

$('#jstree_<?=$config['domain']['safeName']?>').on('activate_node.jstree', function (e, data) {
    if (data.instance.get_node(data.node.id).data.jstree.type == 1) {
        return;
    }
    window.location.href = '<?=htmlspecialchars($_SERVER['SCRIPT_NAME'])?>?do=edit&rowid=' + data.instance.get_node(data.node.id).data.jstree.id;
});

if (document.getElementById('textarea_editor_code')) {
    var editor = CodeMirror.fromTextArea(document.getElementById('textarea_editor_code'), {
        mode: "text/html",
        viewportMargin: Infinity,
        lineNumbers: true,
        lineWrapping: true,
        smartIndent: true,
        matchBrackets: true,
        extraKeys: {
            "Ctrl-S": function () {
                ajaxSaveFile($('#textarea_editor_code').closest('form').attr('id'));
            }
        }
    });
    editor.setSize("100%", '900');
}

tinymce.init({
    selector: 'textarea#textarea_editor_html',
    plugins: "advlist lists charmap print anchor textcolor visualblocks colorpicker fullpage fullscreen code image link imagetools media searchreplace save",
    toolbar: "insert | undo redo | formatselect | bold italic forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | fullpage fullscreen code image link imagetools media searchreplace save",
    removed_menuitems: 'newdocument',
    save_onsavecallback: function () { ajaxSaveFile('formHtml'); },
    valid_elements: "*[*]",
    valid_children: "*[*]",
    theme: "modern",
    cleanup_on_startup: false,
    trim_span_elements: false,
    verify_html: false,
    cleanup: false,
    extended_valid_elements: "*[*]",
    custom_elements: "*[*]",
    allow_conditional_comments: true,
    allow_html_in_named_anchor: true,
    allow_unsafe_link_target: true,
    convert_fonts_to_spans: false,
    branding: false,
    height: 900,
    autoresize_on_init: true,
    relative_urls: true,
    allow_script_urls: true,
    convert_urls: false,
    remove_script_host: true,
    anchor_bottom: false,
    anchor_top: false,
    forced_root_block: false,
    keep_styles: true,
    remove_trailing_brs: false,
    document_base_url: "<?= $config['domain']['root'] . '/' ?>",
    entity_encoding: "named"
});

function logout() {
    $.post({
        cache: false,
        url: '<?=$_SERVER['REQUEST_URI']?>',
        data: 'action=logout',
        success: function () {
            window.location.href = '<?=$_SERVER['REQUEST_URI']?>';
        }
    });
}
</script>
</body>
</html>

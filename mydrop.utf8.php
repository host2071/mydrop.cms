<?php

const MDCMS_VERSION = '1.1';

const MDCMS_PASSWORD = '123qwe';

const MDCMS_DOMAIN = 'viabit.ru';

const MDCMS_LANGUAGE = ['ru', 'en'];

session_start();

if (isset($_POST['password']) && is_string($_POST['password']) && $_POST['password'] === MDCMS_PASSWORD) {
    $_SESSION['mdcms_accepted'] = true;
}
if (strlen(MDCMS_PASSWORD) > 0 && ((isset($_SESSION['mdcms_accepted']) && $_SESSION['mdcms_accepted'] !== true) || !isset($_SESSION['mdcms_accepted'])) ) {
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
        'root' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    ],
    'data' => null
];

$config['db'] = new PDO('sqlite:'.$config['source'].'.mdcms_data'.DIRECTORY_SEPARATOR.'structure.db');
$query = $config['db']->prepare('CREATE TABLE IF NOT EXISTS structure (url TEXT, filename TEXT);');
$query->execute();
$query = $config['db']->prepare('CREATE TABLE IF NOT EXISTS setting (version TEXT, version_checked INTEGER);');
$query->execute();

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'editor.content') {
        $data = getUrlData($_POST['rowid']);
        if (isset($data['filename'])) {
            $filename = $config['source'] . substr($data['filename'], 0, 2) . DIRECTORY_SEPARATOR . substr($data['filename'], 2, 2) . DIRECTORY_SEPARATOR . $data['filename'] . '.html';
            if (file_exists($filename)) {
                file_put_contents($filename, $_POST['content']);
            }
        }
    } else if ($_POST['action'] === 'replacer.do') {
        $_POST['replacer_search'] = str_replace(["\r\n", "\n", "\r"], "\n", $_POST['replacer_search']);
        $_POST['replacer_text'] = str_replace(["\r\n", "\n", "\r"], "\n", $_POST['replacer_text']);
        $query = $config['db']->prepare('SELECT rowid, * FROM structure ORDER BY url');
        $query->execute();
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $filename = $config['source'] . substr($row['filename'], 0, 2) . DIRECTORY_SEPARATOR . substr($row['filename'], 2, 2) . DIRECTORY_SEPARATOR . $row['filename'] . '.html';
            if (file_exists($filename)) {
                file_put_contents($filename, str_replace($_POST['replacer_search'], $_POST['replacer_text'], str_replace(["\r\n", "\n", "\r"], "\n", file_get_contents($filename))));
            }
        }
    } else if ($_POST['action'] === 'logout') {
        unset($_SESSION['mdcms_accepted']);
    }
    echo '[]';
    exit;
}

$query = $config['db']->prepare('SELECT COUNT(*) FROM structure');
$query->execute();
if ($query->fetchColumn() === 0) {
    if (!file_exists($config['dirname'] . DIRECTORY_SEPARATOR . MDCMS_DOMAIN . '.csv')) {
        
        die(t('error_not_found_csv'));
    }
    $handler = fopen($config['dirname'] . DIRECTORY_SEPARATOR . MDCMS_DOMAIN . '.csv', 'r');
    while (($buffer = fgets($handler)) !== false) {
        if (!preg_match('/^'.MDCMS_DOMAIN.'([^;]+);'.MDCMS_DOMAIN.'\/[a-f0-9]{2}\/[a-f0-9]{2}\/([a-f0-9]{32})\.html$/', trim($buffer), $match)) {
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
            if ($explode[$i] !== '' && $i + 1 < $count) {
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

$query = $config['db']->prepare('SELECT COUNT(*) FROM setting');
$query->execute();
if ($query->fetchColumn() === '0') {
    $data = getVersion();
    $query = $config['db']->prepare('INSERT INTO setting (version, version_checked) VALUES (:version, :version_checked)');
    $query->execute([
        'version' => $data === false ? MDCMS_VERSION : $data,
        'version_checked' => time()
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
                    'rowid' => $row['rowid']
                ]);
            } else {
                $query = $config['db']->prepare('UPDATE setting SET version = :version, version_checked = :version_checked WHERE rowid = :rowid');
                $query->execute([
                    'version' => $data,
                    'version_checked' => time(),
                    'rowid' => $row['rowid']
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
    $config['data']['filepath'] =  substr($config['data']['filename'], 0, 2) . DIRECTORY_SEPARATOR . substr($config['data']['filename'], 2, 2) . DIRECTORY_SEPARATOR . $config['data']['filename'] . '.html';
    $config['data']['content'] = file_get_contents($config['source'] . $config['data']['filepath']);
}

function t($key) {
    return isset($GLOBALS['config']['language'][$key]) ? $GLOBALS['config']['language'][$key] : $key;
}

function loadLanguage($key) {
    $languages = [
        'ru' => [
            'footer' => 'сервис для работы с освобождающимися доменами',
            'footer_support' => 'Техническая поддержка — info@mydrop.io',
            'login' => 'Войти',
            'password' => 'Пароль',
            'error_folder' => 'Невозможно создать папку .mdcms_data для хранение файлов',
            'error_not_folder' => 'Файл .mdcms_data должен быть папкой',
            'error_not_found_csv' => 'Файл CSV не найден',
            'error_massive_input' => 'Не заполнены поля',
            'search_replace' => 'Массовое редактирование',
            'single' => 'Постраничное редактирование',
            'massage_success' => 'Задача успешно выполнена',
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
        'en' => [
        ],
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

function createUrlsList() {
    $id = isset($GLOBALS['config']['data']['rowid']) ? $GLOBALS['config']['data']['rowid'] : 0;
    $options = [];
    foreach ($GLOBALS['config']['domain']['urls'] as $key => $value) {
        $options[] = '<option value="'.$key.'"'.($id == $key ? ' selected' : '').'>'.$value.'</option>';
    }
    return implode('', $options);
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
            echo '<li data-jstree=\'{"id":'.$value.',"icon":"glyphicon glyphicon-file","type":2}\'>' . htmlspecialchars(rawurldecode($GLOBALS['config']['domain']['urls'][$value]), ENT_IGNORE) . '</li>';
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
    html,body{height:100%;font-size: 16px;line-height: 1.4;color: #222222;}
    label, .control-label{font-size: 15px;}
    .navbar{border:none;font-size:14px;font-weight:300;border-radius:0;min-height:45px;margin-bottom:21px;}
    .navbar-default{background-color:#333333;border-color:#222222;}
    .container > .navbar-header{margin-right:0;margin-left:0;}
    .wrap{min-height:100%;height:auto;margin:0 auto -85px;padding:0 0 85px;}
    .wrap > .container, .wrap > .container-fluid {padding:60px 15px 20px;}
    .footer{display:block;height:85px;background-color:#eee;border-top:1px solid #ddd;padding:15px 0;}
    .form-horizontal .control-label {text-align: left;font-weight:normal;}
    .jstree-default a {white-space: normal !important;height: auto;}
    .jstree-anchor {height: auto !important;font-size: 0.8em;}
    .jstree-leaf a {height: auto !important;}
    .jstree-default a.jstree-search {color: inherit;}
    .jstree-default-small .jstree-icon:empty{line-height:14px;margin-left:-5px;}
    .jstree-default-small > .jstree-no-dots .jstree-open > .jstree-ocl {background-position:-39px -6px;}
    .CodeMirror {box-sizing: border-box;margin: 0;font: inherit;font-family: inherit;display: block;width: 100%;padding: 0;font-size: 12px;line-height: 1.42857143;color: #555;background-color: #fff;background-image: none;border: 1px solid #ccc;border-radius: 4px;box-shadow: inset 0 1px 1px rgba(0, 0, 0, .075);transition: border-color ease-in-out .15s, box-shadow ease-in-out .15s;font-family: monospace;}
    .CodeMirror-focused {border-color: #66afe9;outline: 0;box-shadow: inset 0 1px 1px rgba(0, 0, 0, .075), 0 0 8px rgba(102, 175, 233, .6);transition: border-color ease-in-out .15s, box-shadow ease-in-out .15s;}
    #textarea_editor_html {min-height: 400px;display: none;white-space: pre-wrap;word-wrap: break-word;}
    .btn.btn-primary[disabled] {background-color:#337ab7;}
    .glyphicon-folder-open{color:#edc237;}
    .glyphicon-file{color:#797d7f;}
    </style>
</head>
<body><div class="wrap"><nav id="w3" class="navbar-default navbar-fixed-top navbar"><div class="container-fluid"><div class="navbar-header"><button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#w3-collapse"><span class="sr-only">Toggle navigation</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span></button><span class="navbar-brand" href="/">MyDrop CMS</span></div><div id="w3-collapse" class="collapse navbar-collapse"><ul id="w4" class="navbar-nav navbar-right nav"><li><a href="https://mydrop.io/domains" target="_blank" rel="noopener noreferrer"><i class="fa fa-table"></i> Список доменов в MyDrop.io</a></li><li><a href="<?=$config['domain']['root']?>" target="_blank" rel="noopener noreferrer"><span class="glyphicon glyphicon-new-window" aria-hidden="true"></span> Перейти на сайт <?=$_SERVER['HTTP_HOST']?></a></li><li><a href="#" onclick="logout(); return false;"><span class="glyphicon glyphicon-off" aria-hidden="true"></span> Выйти</a></li>
</ul></div></div></nav><div class="container-fluid"><div class="row"><div class="col-md-12"><?php if($config['update']){?><div class="alert alert-warning" role="alert"><?=t('need_update')?></div><?php }?><div><ul class="nav nav-tabs" role="tablist"><li role="presentation" class="active"><a href="#editor" aria-controls="editor" role="tab" data-toggle="tab"><?=t('editor')?></a></li><li role="presentation"><a href="#replacer" aria-controls="replacer" role="tab" data-toggle="tab"><?=t('replacer')?></a></li></ul><div class="tab-content" style="margin-top:1%"><div role="tabpanel" class="tab-pane active" id="editor"><div class="row"><div class="col-sm-4 col-md-3"><div class="panel panel-default"><div class="panel-body"><div id="jstree_loading_alert"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span> Loading URL list ...</div><div id="jstree_<?=$config['domain']['safeName']?>" data-domain-converted="<?=$config['domain']['safeName']?>" style="display:none;"><?php createTree($config['domain']['tree']); ?></div></div></div></div><div class="col-sm-8 col-md-9"><div class="panel panel-default"><div class="panel-heading"><?=t('editor_header')?></div><div class="panel-body"><form class="form-horizontal"><div class="form-group"><label class="col-sm-2 col-md-1 control-label">URL: </label><div class="col-sm-10 col-md-11"><div class="input-group"><input id="editor_url" type="text" class="form-control" value="<?=isset($config['data']['url']) ? $config['data']['url'] : ''?>" readonly="readonly" / ><div class="input-group-btn"><a class="btn btn-default" onclick="copyLink(); return false;"><i class="glyphicon glyphicon-link" aria-hidden="true"></i></a><a class="btn btn-default" href="<?= $config['domain']['root'] . (isset($config['data']['url']) ? $config['data']['url'] : ''); ?>" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-new-window" aria-hidden="true"></i></a></div></div></div></div><div class="form-group"><label class="col-sm-2 col-md-1 control-label">Файл: </label><div class="col-sm-10 col-md-11"><input type="text" class="form-control" value="<?=isset($config['data']['filepath']) ? $config['data']['filepath'] : ''?>" readonly="readonly" / ></div></div></form></div></div><ul class="nav nav-tabs" role="tablist"><li role="presentation" class="active"><a href="#editor_html" aria-controls="editor_html" role="tab" data-toggle="tab"><?=t('WYSIWYG')?></a></li><li role="presentation"><a id="editor_code-tab" href="#editor_code" aria-controls="editor_code" role="tab" data-toggle="tab"><?=t('CODE')?></a></li></ul><div class="alert alert-success text-center" id="savedAlert" role="alert" style="display:none;margin-top:1%;"><?=t('message_saved')?></div><div class="tab-content" style="margin-top:1%"><div role="tabpanel" class="tab-pane active" id="editor_html"><form id="formHtml" action="" method="post" class="form-horizontal" onsubmit="ajaxSaveFile('formHtml'); return false;" autocomplete="off"><input type="hidden" name="action" value="editor.content"/><input type="hidden" name="rowid" value="<?=$config['data']['rowid']?>"/><div class="form-group"><div class="col-md-12"><textarea id="textarea_editor_html" name="content"><?=htmlspecialchars($config['data']['content']);?></textarea></div></div><div class="form-group"><div class="col-md-12"><button type="submit" class="btn btn-primary"><?=t('save')?></button></div></div></form></div><div role="tabpanel" class="tab-pane" id="editor_code"><form id="formCode" action="" method="post" class="form-horizontal" onsubmit="ajaxSaveFile('formCode'); return false;" autocomplete="off"><input type="hidden" name="action" value="editor.content"/><input type="hidden" name="rowid" value="<?=$config['data']['rowid']?>"/><div class="form-group"><div class="col-md-12"><textarea id="textarea_editor_code" name="content"><?=htmlspecialchars($config['data']['content']);?></textarea></div></div><div class="form-group"><div class="col-md-12"><button type="submit" class="btn btn-primary"><?=t('save')?></button></div></div></form></div></div></div></div></div><div role="tabpanel" class="tab-pane" id="replacer"><div class="alert alert-success text-center" id="formReplacerAlert" role="alert" style="display:none;margin-top:1%;"><?=t('message_saved')?></div><div class="panel panel-default"><div class="panel-heading"><?=t('replacer_header')?></div><div class="panel-body"><form id="formReplacer" action="" method="post" class="form-horizontal" onsubmit="ajaxReplacer(); return false;"><input type="hidden" name="action" value="replacer.do"/><div class="form-group"><label for="inputEmail3" class="col-sm-2 col-md-1 control-label"><?=t('search')?>: </label><div class="col-sm-10 col-md-11"><textarea class="form-control" name="replacer_search" rows="4" style="resize:none;"></textarea></div></div><div class="form-group"><label for="inputPassword3" class="col-sm-2 col-md-1 control-label"><?=t('replace')?>: </label><div class="col-sm-10 col-md-11"><textarea class="form-control" name="replacer_text" rows="4" style="resize:none;"></textarea></div></div><div class="form-group"><div class="col-md-12"><span class="pull-right"><button type="submit" class="btn btn-primary form-control"><?=t('do')?></button></span></div></div></form></div></div></div></div></div></div></div></div></div><footer class="footer"><div class="container"><p class="text-center">&copy; 2016–2019 <a href="https://mydrop.io" target="_blank" rel="noopener noreferrer">MyDrop.io</a> — <?=t('footer')?></p><p class="text-center"><?=t('footer_support')?></p></div></footer>
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
$(document).ready(function () {
    $('#editor_code-tab').on('shown.bs.tab', function () {
        editor.refresh();
    });
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
    var url = "<?=$_SERVER['REQUEST_URI']?>";
    $.post({
        cache: false,
        url: url,
        data: $('#' + form).serialize(),
        success: function (data) {
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
    var url = "<?=$_SERVER['REQUEST_URI']?>";
    $.post({
        cache: false,
        url: url,
        data: $('#formReplacer').serialize(),
        success: function (data) {
            $('html, body').animate({ scrollTop: 0 }, 'fast');
            $('#formReplacerAlert').show('fast').delay(2000).hide('fast');
            $(':input[type="submit"]').removeAttr('disabled');
        }
    });
    return false;
}

$('#jstree_<?=$config['domain']['safeName']?>').bind('loaded.jstree', function(e, data) {
    $('#jstree_loading_alert').css('display', 'none');
    $('#jstree_<?=$config['domain']['safeName']?>').css('display', 'block');
}).jstree({
    "plugins": [
        "wholerow",
        "search",
        "sort",
        "state",
    ],
    "search": {
        "case_sensitive": false,
        "show_only_matches": true
    },
    "core": {
        "themes": {
            "variant": "small",
        },
        "multiple": false,
    },
    "state": {
        "key": "domainname",
    },
    "sort": function (a, b) {
        a1 = this.get_node(a);
        b1 = this.get_node(b);
        if (a1.data.jstree.type == b1.data.jstree.type) {
            return (a1.text > b1.text) ? 1 : -1;
        } else {
            return (a1.data.jstree.type > b1.data.jstree.type) ? 1 : -1;
        }
    },
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
            "Ctrl-S": function (instance) {
                ajaxSaveFile($('#textarea_editor_code').closest('form').attr('id'));
            },
        }
    });
    editor.setSize("100%", '900');
}

tinymce.init({
    selector: 'textarea#textarea_editor_html',
    plugins: "advlist lists charmap print anchor textcolor visualblocks colorpicker  fullpage fullscreen code image link imagetools media searchreplace save",
    toolbar: "insert | undo redo |  formatselect | bold italic forecolor backcolor  | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | fullpage fullscreen code image link imagetools media searchreplace save",
    removed_menuitems: 'newdocument',
    save_onsavecallback: function () {
        ajaxSaveFile('formHtml');
    },
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
    entity_encoding: "named",
});

function logout() {
    $.post({
        cache: false,
        url: '<?=$_SERVER['REQUEST_URI']?>',
        data: 'action=logout',
        success: function (data) {
            window.location.href = '<?=$_SERVER['REQUEST_URI']?>';
        }
    });
}
</script>
</body>
</html>
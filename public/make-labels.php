<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\CsvUtil;
use App\LabelPdf;

date_default_timezone_set('Europe/Berlin');

/* ---------- Helpers ---------- */
function tooltip_texts(): array {
    return [
        'presets_help' => 'Voreinstellungen (Presets) speichern, laden oder löschen. Wenn du beim Speichern keinen Namen eingibst, wird das aktuell gewählte Preset überschrieben.',
        'options_help' => '„Erste Zeile enthält Überschriften“: Die erste Zeile der Datei sind nur Spaltennamen. „Leere Datensätze überspringen“: Zeilen ohne Inhalt werden ignoriert.',
        'fine_help'    => 'Ränder und Abstände für den Druck einstellen: Top/Left = Abstand zum Seitenrand, H-/V-Gap = Abstand zwischen Etiketten, Padding = Innenabstand im Etikett, FontSize = Schriftgröße.',
        'presets_assignment' => 'Ordne die Spalten deiner Datei den Feldern zu. Wähle, welche Spalte Vorname, Nachname, Straße, PLZ und Ort enthält.',
        'delimiter'   => 'Trennzeichen zwischen Spalten in der CSV. Meist Semikolon oder Komma. „auto“ erkennt das Trennzeichen automatisch.',
    ];
}


/** Erzeugt ein Bootstrap-Tooltip-Icon. */
function showToolTip(string $key, string $placement='top', string $customClass='custom-tooltip', string $extraClasses='help'): string {
    $texts = tooltip_texts();
    $txt   = $texts[$key] ?? $key;

    // 'help' immer dabei, Duplikate entfernen
    $parts   = preg_split('/\s+/', trim('help ' . $extraClasses)) ?: [];
    $parts   = array_values(array_unique(array_filter($parts, fn($c) => $c !== '')));
    $classes = implode(' ', $parts);

    return '<span class="'.h($classes).'" data-bs-toggle="tooltip" data-bs-placement="'.h($placement).'" data-bs-custom-class="'.h($customClass).'" data-bs-title="'.h($txt).'"><i class="fa-solid fa-circle-question"></i></span>';
}


function fonts_available(): array {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fonts';
    $out = [];
    if (is_dir($base)) {
        foreach (scandir($base) ?: [] as $d) {
            if ($d === '.' || $d === '..') continue;
            $dir = $base . DIRECTORY_SEPARATOR . $d;
            if (!is_dir($dir)) continue;
            $hasFont = glob($dir . DIRECTORY_SEPARATOR . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE);
            if ($hasFont) $out[] = $d;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function delete_preset(string $name): bool {
    $file = preset_dir() . DIRECTORY_SEPARATOR . sanitize_preset($name) . '.json';
    return is_file($file) ? @unlink($file) : false;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function upload_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function tmp_ok(string $p): bool {
    $rp = realpath($p);
    if ($rp === false) return false;
    $base1 = realpath(sys_get_temp_dir());
    $base2 = realpath(upload_dir());
    return ($base1 && str_starts_with($rp, $base1)) || ($base2 && str_starts_with($rp, $base2));
}
function preset_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'presets';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function sanitize_preset(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\s+/', '_', $name);
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $name);
    return substr($name, 0, 64) ?: 'preset';
}
function load_presets(): array {
    $dir = preset_dir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        $out[] = basename($f, '.json');
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function read_preset(string $name): ?array {
    $file = preset_dir() . DIRECTORY_SEPARATOR . sanitize_preset($name) . '.json';
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
function save_preset(string $name, array $data): void {
    $file = preset_dir() . DIRECTORY_SEPARATOR . sanitize_preset($name) . '.json';
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
/** Aus unserem Upload-Dateinamen label_<uniq>_<orig> den Originalnamen extrahieren. */
function active_name_from_path(string $p): string {
    $b = basename($p);
    $pos = strrpos($b, '_');
    return ($pos !== false) ? substr($b, $pos + 1) : $b;
}

/* ---------- UI Partials ---------- */
function render_upload_card(?string $activeName = null): void {
    echo '<h2>Schritt 1: Datei hochladen</h2>';
    echo '<div class="card shadow-sm mb-3">';
    echo '  <div class="card-header d-flex justify-content-between align-items-center">';
    echo '    <span>Datei hochladen</span>';
    if ($activeName) {
        echo '    <span class="badge text-bg-success">Aktive Datei: <code>'.h($activeName).'</code></span>';
    }
    echo '  </div>';
    echo '  <div class="card-body">';
    echo '<p>Hier bitte die CSV- oder Word-Datei mit den Adressen auf dem PC/USB-Stick auswählen und hochladen.</p>';
    echo '    <form action="make-labels.php" method="post" enctype="multipart/form-data" class="row g-3">';
    echo '      <div class="col-12">';
    echo '        <label for="inputFile" class="form-label">Datei (.csv oder .docx)</label>';
    echo '        <input class="form-control" type="file" id="inputFile" name="input" required>';
    echo '      </div>';
    echo '      <div class="col-md-4">';
    echo '        <label for="delimiter" class="form-label">Delimiter';
    echo showToolTip('delimiter', 'right', 'help', 'inline');
    echo '        </label>';
    echo '        <select id="delimiter" name="delimiter" class="form-select">';
    echo '          <option value="">auto</option>';
    echo '          <option value=";">Semikolon (;)</option>';
    echo '          <option value=",">Komma (,)</option>';
    echo '          <option value="\\t">Tab</option>';
    echo '          <option value="|">Pipe (|)</option>';
    echo '        </select>';
    echo '      </div>';
    echo '      <div class="col-12 d-flex gap-2">';
    echo '        <button class="btn btn-primary" type="submit">Hochladen</button>';
    // echo '        <span class="text-muted align-self-center">.docx wird nur als .csv kopiert/umbenannt.</span>';
    echo '      </div>';
    echo '    </form>';
    echo '  </div>';
    echo '</div>';
}

/* ---------- UI: Mapping ---------- */
function render_mapping_form(array $args): void {
    [
        'csvPath'   => $csvPath,
        'enc'       => $enc,
        'delimiter' => $delimiter,
        'cfg'       => $cfg,
        'sample'    => $sample,
        'maxCols'   => $maxCols,
        'hasHeader' => $hasHeader,
        'map'       => $map,
        'skipEmpty' => $skipEmpty,
        'message'   => $message,
        'selectedPreset' => $selectedPreset
    ] = $args + ['selectedPreset' => ''];

    $presets = load_presets();
    $fields = [
        'firstname' => 'Vorname',
        'lastname'  => 'Nachname',
        'street'    => 'Straße',
        'zip'       => 'PLZ',
        'city'      => 'Ort',
    ];

    // Defaults
    $cfg = array_merge([
        'top'=>10.0,'left'=>0.0,'pad'=>3.0,'hgap'=>0.0,'vgap'=>0.0,'fontsize'=>9.0,
        'font'=>'builtin:dejavusans'
    ], $cfg ?? []);
    if (isset($cfg['font']) && !str_contains((string)$cfg['font'], ':')) {
        $cfg['font'] = 'builtin:' . $cfg['font'];
    }

    if ($message) {
        echo '<div class="alert alert-info" role="alert">'.h($message).'</div>';
        echo "<script>window.addEventListener('DOMContentLoaded',function(){alert(".json_encode($message).");});</script>";
    }

    echo '<form method="post" action="make-labels.php" class="d-grid gap-3">';
    echo '<input type="hidden" name="stage" value="mapping">';
    echo '<input type="hidden" name="csv_path" value="'.h($csvPath).'">';
    echo '<input type="hidden" name="enc" value="'.h($enc).'">';
    echo '<input type="hidden" name="delimiter" value="'.h($delimiter).'">';

    echo '<h2>Schritt 2: Preset anwenden (oder Einstellungen vornehmen)</h2>';

    /* Accordion 1: Optionen */
    echo '<div class="accordion" id="accOptions">';
    echo '  <div class="accordion-item">';
    echo '    <h2 class="accordion-header" id="hdOpt">';
    echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clOpt" aria-expanded="false" aria-controls="clOpt">Optionen</button>';
    echo '    </h2>';
    echo '    <div id="clOpt" class="accordion-collapse collapse" aria-labelledby="hdOpt" data-bs-parent="#accOptions">';
    echo '      <div class="accordion-body">';
    echo '        <div class="form-check form-check-inline">';
    echo '          <input class="form-check-input" type="checkbox" id="has_header" name="has_header" value="1" '.($hasHeader?'checked':'').'>';
    echo '          <label class="form-check-label" for="has_header">Erste Zeile enthält Überschriften</label>';
    echo '        </div>';
    echo '        <div class="form-check form-check-inline ms-3">';
    echo '          <input class="form-check-input" type="checkbox" id="skip_empty" name="skip_empty" value="1" '.($skipEmpty?'checked':'').'>';
    echo '          <label class="form-check-label" for="skip_empty">Leere Datensätze überspringen</label>';
    echo '        </div>';
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';

    /* Accordion 2: Feineinstellungen + Hinweise */
    echo '  <div class="accordion-item">';
    echo '    <h2 class="accordion-header" id="hdFine">';
    echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clFine" aria-expanded="false" aria-controls="clFine">Feineinstellungen & Hinweise</button>';
    echo '    </h2>';
    echo '    <div id="clFine" class="accordion-collapse collapse" aria-labelledby="hdFine" data-bs-parent="#accOptions">';
    echo '      <div class="accordion-body">';
    echo '        <div class="row g-3 mb-3">';
    $num = function($id,$label,$name,$val,$step) {
        echo '<div class="col-sm-6 col-md-2"><label for="'.$id.'" class="form-label">'.$label.'</label>'.
            '<input id="'.$id.'" name="'.$name.'" type="number" step="'.$step.'" class="form-control" value="'.h((string)$val).'"></div>';
    };
    $num('cfg_top','Top (mm)','cfg[top]',$cfg['top'],'0.1');
    $num('cfg_left','Left (mm)','cfg[left]',$cfg['left'],'0.1');
    $num('cfg_pad','Padding (mm)','cfg[pad]',$cfg['pad'],'0.1');
    $num('cfg_hgap','H-Gap (mm)','cfg[hgap]',$cfg['hgap'],'0.1');
    $num('cfg_vgap','V-Gap (mm)','cfg[vgap]',$cfg['vgap'],'0.1');
    $num('cfg_fontsize','FontSize (pt)','cfg[fontsize]',$cfg['fontsize'],'0.5');

    $fontFolders = fonts_available();
    echo '          <div class="col-sm-6 col-md-4">';
    echo '            <label for="cfg_font" class="form-label">Font</label>';
    echo '            <select id="cfg_font" name="cfg[font]" class="form-select">';
    $cur = (string)$cfg['font'];
    $opt = 'builtin:dejavusans';
    echo '              <option value="'.h($opt).'"'.($cur===$opt?' selected':'').'>DejaVu Sans (eingebaut, empfohlen)</option>';
    foreach ($fontFolders as $f) {
        $val = 'folder:' . $f;
        $sel = $cur === $val ? ' selected' : '';
        echo '            <option value="'.h($val).'"'.$sel.'>'.h($f).' (fonts/'.h($f).')</option>';
    }
    echo '            </select>';
    echo '          </div>';
    echo '        </div>';
    echo '        <div class="row"><div class="col-md-6">';
    echo '          <ul class="mb-0">';
    echo '            <li><strong>Top/Left:</strong> Seitenränder justieren.</li>';
    echo '            <li><strong>Padding:</strong> Innenabstand im Etikett.</li>';
    echo '            <li><strong>H/V-Gap:</strong> Zwischenräume zwischen Spalten/Zeilen.</li>';
    echo '            <li><strong>FontSize:</strong> Schriftgröße in pt.</li>';
    echo '          </ul>';
    echo '        </div><div class="col-md-6">';
    echo '          <ul class="mb-0">';
    echo '            <li>Start: Top 10, Left 0, Pad 3, H/V-Gap 0, FontSize 9.</li>';
    echo '            <li>Skalierung: 100 %, A4, keine Kopf-/Fußzeilen.</li>';
    echo '          </ul>';
    echo '        </div></div>';
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // Presets (sichtbar)
    echo '<div class="card shadow-sm mt-3">';
    echo ' <div class="card-header">Presets</div>';


    echo showToolTip('presets_help');

    echo ' <div class="card-body">';
    echo '  <div class="row g-3 align-items-end">';
    echo '    <div class="col-md-4">';
    echo '      <label for="preset_select" class="form-label">Preset laden</label>';
    echo '      <select name="preset_select" id="preset_select" class="form-select">';
    echo '        <option value="">— wählen —</option>';
    foreach ($presets as $p) {
        $selAttr = ($selectedPreset === $p) ? ' selected' : '';
        echo '      <option value="'.h($p).'"'.$selAttr.'>'.h($p).'</option>';
    }
    echo '      </select>';
    echo '    </div>';
    echo '    <div class="col-auto">';
    echo '      <button name="action" value="apply_preset" type="submit" class="btn btn-outline-secondary">Preset anwenden</button>';
    echo '    </div>';
    echo '    <div class="col-md-4">';
    echo '      <label for="preset_name" class="form-label">Als Preset speichern</label>';
    echo '      <input type="text" name="preset_name" id="preset_name" class="form-control" placeholder="Name">';
    echo '      <input type="hidden" name="preset_overwrite" id="preset_overwrite" value="">';
    echo '      <input type="hidden" name="confirm_delete" id="confirm_delete" value="">';
    echo '    </div>';
    echo '    <div class="col-auto d-flex gap-2">';
    echo '      <button id="btn_save_preset" name="action" value="save_preset" type="submit" class="btn btn-primary">Speichern</button>';
    $delLabel = $selectedPreset ? ('Preset '.$selectedPreset.' löschen') : 'Preset löschen';
    echo '      <button id="btn_delete_preset" name="action" value="delete_preset" type="submit" class="btn btn-danger">'.$delLabel.'</button>';
    echo '    </div>';
    echo '  </div>';
    echo ' </div>';
    echo '</div>';

    // Zuordnung (sichtbar)
    echo '<div class="card shadow-sm mt-3">';
    echo ' <div class="card-header">Zuordnung</div>';
    echo showToolTip('presets_assignment');
    echo ' <div class="card-body">';
    echo '  <div class="row g-3">';
    foreach ($fields as $key => $label) {
        $selectedIdx = isset($map[$key]) && $map[$key] !== '' ? (int)$map[$key] : null;
        echo '  <div class="col-md-6">';
        echo '    <label class="form-label">'.h($label).'</label>';
        echo '    <select name="map['.$key.']" class="form-select">';
        echo '      <option value="">— nicht zuordnen —</option>';
        for ($i=0; $i<$maxCols; $i++) {
            $example = '';
            foreach ($sample as $row) {
                if (isset($row[$i]) && trim((string)$row[$i]) !== '') { $example = (string)$row[$i]; break; }
            }
            $optLabel = 'Spalte '.($i+1).($example!=='' ? ' — Bsp: '.mb_strimwidth($example,0,40,'…','UTF-8') : '');
            $sel = ($selectedIdx === $i) ? ' selected' : '';
            echo '  <option value="'.$i.'"'.$sel.'>'.h($optLabel).'</option>';
        }
        echo '    </select>';
        echo '  </div>';
    }
    echo '  </div>';
    echo ' </div>';
    echo '</div>';

    // CSV-Vorschau
    if ($sample) {
        echo '<div class="card shadow-sm mt-3">';
        echo ' <div class="card-header">CSV-Vorschau</div>';
        echo ' <div class="card-body p-0">';
        echo '  <div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead><tr>';
        for ($i=0; $i<$maxCols; $i++) echo '<th>Spalte '.($i+1).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($sample as $r) {
            echo '<tr>';
            for ($i=0; $i<$maxCols; $i++) echo '<td>'.h($r[$i] ?? '').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo ' </div>';
        echo '</div>';
    }

    // Submit
    echo '<h2>Schritt 3: PDF-Datei erzeugen</h2>';
    echo '<div class="d-flex gap-2 mt-3">';
    echo '  <button name="action" value="generate" type="submit" class="btn btn-success">PDF erzeugen</button>';
    echo '</div>';

    // JS
    echo '<script>
function updateDeleteLabel(){
  var sel = document.getElementById("preset_select").value;
  var btn = document.getElementById("btn_delete_preset");
  btn.textContent = sel ? ("Preset " + sel + " löschen") : "Preset löschen";
}
document.getElementById("preset_select").addEventListener("change", updateDeleteLabel);
updateDeleteLabel();

document.getElementById("btn_save_preset").addEventListener("click", function(ev){
  var sel = document.getElementById("preset_select").value;
  var name = document.getElementById("preset_name").value.trim();
  if (sel && !name) {
    if (confirm("Preset \\"" + sel + "\\" überschreiben?")) {
      document.getElementById("preset_overwrite").value = "1";
    } else { ev.preventDefault(); return false; }
  }
});
document.getElementById("btn_delete_preset").addEventListener("click", function(ev){
  var sel = document.getElementById("preset_select").value;
  if (!sel) { ev.preventDefault(); alert("Kein Preset gewählt."); return false; }
  if (confirm("Preset \\"" + sel + "\\" löschen?")) {
    document.getElementById("confirm_delete").value = "1";
  } else { ev.preventDefault(); return false; }
});
</script>';

    echo '</form>';
}

/* ---------- EARLY: PDF-Generate ohne vorherige Ausgabe ---------- */
if (
    ($_SERVER['REQUEST_METHOD'] === 'POST') &&
    (($_POST['stage'] ?? '') === 'mapping') &&
    (($_POST['action'] ?? '') === 'generate')
) {
    $csvPath   = $_POST['csv_path'] ?? '';
    $enc       = $_POST['enc'] ?? 'UTF-8';
    $delimiter = $_POST['delimiter'] ?? ';';
    $cfg       = $_POST['cfg'] ?? [];
    $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
    $skipEmpty = isset($_POST['skip_empty']) && $_POST['skip_empty'] === '1';
    $map       = $_POST['map'] ?? [];
    $selectedPreset = $_POST['preset_select'] ?? '';

    try {
        if (!$csvPath || !is_file($csvPath) || !tmp_ok($csvPath)) {
            throw new RuntimeException('CSV nicht verfügbar. Bitte neu hochladen.');
        }

        $sample = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);
        $maxCols = 0; foreach ($sample as $r) $maxCols = max($maxCols, count($r));

        $rows = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, null);
        if ($hasHeader && $rows) array_shift($rows);

        $labels = [];
        foreach ($rows as $row) {
            $get = function(string $key) use ($map, $row): string {
                if (!isset($map[$key]) || $map[$key] === '') return '';
                $idx = (int)$map[$key];
                return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            };
            $vname = $get('firstname');
            $nname = $get('lastname');
            $street= $get('street');
            $zip   = $get('zip');
            $city  = $get('city');

            $allEmpty = ($vname==='' && $nname==='' && $street==='' && $zip==='' && $city==='');
            if ($skipEmpty && $allEmpty) continue;

            $line1 = trim($vname . ' ' . $nname);
            $line2 = $street;
            $line3 = trim($zip . ' ' . $city);
            $lines = array_values(array_filter([$line1, $line2, $line3], fn($x) => $x !== ''));

            if (!$skipEmpty && $allEmpty) $lines = [];

            $labels[] = $lines;
        }

        if (!$labels) {
            // UI zurückgeben, da kein Download entsteht
            $pageTitle = 'LabelMaker';
            require __DIR__ . '/partials/header.php';
            render_upload_card(active_name_from_path($csvPath));
            $msg = 'Keine Etiketten nach Mapping.';
            render_mapping_form([
                'csvPath'=>$csvPath,'enc'=>$enc,'delimiter'=>$delimiter,
                'cfg'=>$cfg,'sample'=>$sample,'maxCols'=>$maxCols,
                'hasHeader'=>$hasHeader,'map'=>$map,'skipEmpty'=>$skipEmpty,
                'message'=>$msg,'selectedPreset'=>$selectedPreset
            ]);
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        $lp = [
            'topMargin'  => isset($cfg['top']) ? (float)$cfg['top'] : 10.0,
            'leftMargin' => isset($cfg['left']) ? (float)$cfg['left'] : 0.0,
            'padX'       => isset($cfg['pad']) ? (float)$cfg['pad'] : 3.0,
            'padY'       => isset($cfg['pad']) ? (float)$cfg['pad'] : 3.0,
            'hGap'       => isset($cfg['hgap']) ? (float)$cfg['hgap'] : 0.0,
            'vGap'       => isset($cfg['vgap']) ? (float)$cfg['vgap'] : 0.0,
            'fontSize'   => isset($cfg['fontsize']) ? (float)$cfg['fontsize'] : 9.0,
            'font'       => isset($cfg['font']) ? (string)$cfg['font'] : 'builtin:dejavusans',
        ];

        $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('etiketten_', true) . '.pdf';
        (new LabelPdf($lp))->generateAvery3481($labels, $pdfPath);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        $downloadName = 'Adress-Etiketten-' . date('Y-m-d_H-i') . '.pdf';
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string)filesize($pdfPath));
        header('X-Content-Type-Options: nosniff');
        readfile($pdfPath);
    } catch (Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Fehler: " . $e->getMessage();
    } finally {
        if (isset($pdfPath) && is_file($pdfPath)) @unlink($pdfPath);
    }
    exit;
}

/* ---------- Controller (UI) ---------- */
$pageTitle = 'LabelMaker';
require __DIR__ . '/partials/header.php';

$activeName = null;

/* Stage A: Upload -> Mapping */
if (isset($_FILES['input']) && $_FILES['input']['error'] === UPLOAD_ERR_OK) {
    $origName = $_FILES['input']['name'] ?? 'input';
    $upload   = upload_dir() . DIRECTORY_SEPARATOR . uniqid('label_', true) . '_' . basename($origName);
    if (!move_uploaded_file($_FILES['input']['tmp_name'], $upload)) {
        render_upload_card();
        echo '<div class="alert alert-danger">Konnte Upload nicht speichern</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    $cfg = [
        'top'      => isset($_POST['top']) ? (float)$_POST['top'] : 10.0,
        'left'     => isset($_POST['left']) ? (float)$_POST['left'] : 0.0,
        'pad'      => isset($_POST['pad']) ? (float)$_POST['pad'] : 3.0,
        'hgap'     => isset($_POST['hgap']) ? (float)$_POST['hgap'] : 0.0,
        'vgap'     => isset($_POST['vgap']) ? (float)$_POST['vgap'] : 0.0,
        'fontsize' => isset($_POST['fontsize']) ? (float)$_POST['fontsize'] : 9.0,
    ];
    $forcedDelimiter = $_POST['delimiter'] ?? null;

    try {
        $csvPath   = CsvUtil::ensureCsv($upload);
        $enc       = CsvUtil::detectEncoding($csvPath);
        $delimiter = CsvUtil::detectDelimiter($csvPath, $enc, $forcedDelimiter);
        $sample    = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);

        $maxCols = 0; foreach ($sample as $r) $maxCols = max($maxCols, count($r));
        $hasHeaderDefault = false;
        if ($sample && count($sample) >= 2) {
            $allAlpha = fn($row) => count(array_filter($row, fn($c) => preg_match('/[A-Za-zÄÖÜäöüß]/u', (string)$c))) >= min(2, max(1, (int)floor(count($row)/2)));
            $hasHeaderDefault = $allAlpha($sample[0]) && !$allAlpha($sample[1]);
        }

        $activeName = $origName;
        render_upload_card($activeName);

        render_mapping_form([
            'csvPath'   => $csvPath,
            'enc'       => $enc,
            'delimiter' => $delimiter,
            'cfg'       => $cfg,
            'sample'    => $sample,
            'maxCols'   => $maxCols,
            'hasHeader' => $hasHeaderDefault,
            'map'       => [],
            'skipEmpty' => true,
            'message'   => null
        ]);
        require __DIR__ . '/partials/footer.php';
        exit;
    } catch (Throwable $e) {
        render_upload_card();
        echo '<div class="alert alert-danger">Fehler: '.h($e->getMessage()).'</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }
}

/* Stage Mapping: apply/save/delete (generate wird oben abgefangen) */
$stage          = $_POST['stage']   ?? '';
$action         = $_POST['action']  ?? '';
$csvPath        = $_POST['csv_path']?? '';
$enc            = $_POST['enc']     ?? 'UTF-8';
$delimiter      = $_POST['delimiter'] ?? ';';
$cfg            = $_POST['cfg'] ?? [];
$hasHeader      = isset($_POST['has_header']) && $_POST['has_header']==='1';
$skipEmpty      = isset($_POST['skip_empty']) && $_POST['skip_empty']==='1';
$map            = $_POST['map'] ?? [];
$selectedPreset = $_POST['preset_select'] ?? '';

if ($stage === 'mapping') {
    if (!$csvPath || !is_file($csvPath) || !tmp_ok($csvPath)) {
        render_upload_card();
        echo '<div class="alert alert-warning">Die hochgeladene CSV ist nicht verfügbar. Bitte neu hochladen.</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    $sample = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);
    $maxCols = 0; foreach ($sample as $r) $maxCols = max($maxCols, count($r));
    $activeName = active_name_from_path($csvPath);
    render_upload_card($activeName);

    if ($action === 'apply_preset') {
        $sel = $_POST['preset_select'] ?? '';
        $pr  = $sel ? read_preset($sel) : null;
        if ($pr) {
            $map       = $pr['map']        ?? $map;
            $hasHeader = (bool)($pr['has_header'] ?? $hasHeader);
            $skipEmpty = (bool)($pr['skip_empty'] ?? $skipEmpty);

            if (!empty($pr['cfg']) && is_array($pr['cfg'])) {
                foreach (['top','left','pad','hgap','vgap','fontsize','font'] as $k) {
                    if (array_key_exists($k, $pr['cfg'])) {
                        $cfg[$k] = ($k === 'font') ? (string)$pr['cfg'][$k] : (float)$pr['cfg'][$k];
                    }
                }
            }

            foreach ($map as $k=>$v) {
                if ($v === '' || $v === null) continue;
                $idx = (int)$v;
                if ($idx < 0 || $idx >= $maxCols) unset($map[$k]);
            }
            $msg = "Preset „{$sel}” angewendet.";
        } else {
            $msg = "Kein gültiges Preset gewählt.";
        }
        render_mapping_form(
            compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
            + ['message'=>$msg, 'selectedPreset'=>$sel]
        );
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    if ($action === 'save_preset') {
        $sel = $_POST['preset_select'] ?? '';
        $rawName = trim((string)($_POST['preset_name'] ?? ''));
        $overwrite = ($_POST['preset_overwrite'] ?? '') === '1';

        if ($rawName === '' && $sel !== '') {
            if (!$overwrite) {
                $msg = "Soll das Preset „{$sel}” überschrieben werden? Bitte erneut auf „Speichern” klicken und bestätigen.";
                render_mapping_form(
                    compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
                    + ['message'=>$msg, 'selectedPreset'=>$sel]
                );
                require __DIR__ . '/partials/footer.php';
                exit;
            }
            $name = sanitize_preset($sel);
        } else {
            if ($rawName === '') {
                $msg = "Kein Presetname angegeben.";
                render_mapping_form(
                    compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
                    + ['message'=>$msg, 'selectedPreset'=>$sel]
                );
                require __DIR__ . '/partials/footer.php';
                exit;
            }
            $name = sanitize_preset($rawName);
        }

        $selectedPreset = $name;

        $cfgToSave = [];
        foreach (['top','left','pad','hgap','vgap','fontsize','font'] as $k) {
            if (!array_key_exists($k, $cfg)) continue;
            $cfgToSave[$k] = ($k === 'font') ? (string)$cfg[$k] : (float)$cfg[$k];
        }

        $hasAny = false; foreach ($map as $v) if ($v !== '' && $v !== null) { $hasAny = true; break; }
        if (!$hasAny) {
            $msg = "Kein Feld zugeordnet. Preset nicht gespeichert.";
        } else {
            save_preset($name, [
                'map'        => $map,
                'has_header' => $hasHeader,
                'skip_empty' => $skipEmpty,
                'cfg'        => $cfgToSave,
                'ts'         => time()
            ]);
            $msg = "Preset „{$name}” gespeichert.";
        }
        render_mapping_form(
            compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
            + ['message'=>$msg, 'selectedPreset'=>$selectedPreset]
        );
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    if ($action === 'delete_preset') {
        $sel = $_POST['preset_select'] ?? '';
        $confirmed = ($_POST['confirm_delete'] ?? '') === '1';

        if ($sel === '') {
            $msg = "Kein Preset gewählt.";
            render_mapping_form(
                compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
                + ['message'=>$msg, 'selectedPreset'=>$sel]
            );
            require __DIR__ . '/partials/footer.php';
            exit;
        }
        if (!$confirmed) {
            $msg = "Löschen bestätigen.";
            render_mapping_form(
                compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
                + ['message'=>$msg, 'selectedPreset'=>$sel]
            );
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        $ok = delete_preset($sel);
        $msg = $ok ? "Preset „{$sel}” gelöscht." : "Preset „{$sel}” nicht gefunden.";
        $selectedPreset = '';
        render_mapping_form(
            compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty')
            + ['message'=>$msg, 'selectedPreset'=>$selectedPreset]
        );
        require __DIR__ . '/partials/footer.php';
        exit;
    }
}

/* Initial GET oder kein passender POST: nur Upload-Form sichtbar */
render_upload_card();
require __DIR__ . '/partials/footer.php';

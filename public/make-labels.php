<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\CsvUtil;
use App\LabelPdf;

date_default_timezone_set('Europe/Berlin');

/* ---------- Helpers ---------- */

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

function delete_preset(string $name): bool {
    $file = preset_dir() . DIRECTORY_SEPARATOR . sanitize_preset($name) . '.json';
    return is_file($file) ? @unlink($file) : false;
}

function get_default_preset(): string {
    $file = preset_dir() . DIRECTORY_SEPARATOR . '.default';
    if (!is_file($file)) return '';
    return trim((string)@file_get_contents($file));
}

function save_default_preset(string $name): void {
    $file = preset_dir() . DIRECTORY_SEPARATOR . '.default';
    if ($name === '') { @unlink($file); return; }
    @file_put_contents($file, sanitize_preset($name));
}

function fonts_available(): array {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fonts';
    $out = [];
    if (is_dir($base)) {
        foreach (scandir($base) ?: [] as $d) {
            if ($d === '.' || $d === '..') continue;
            $dir = $base . DIRECTORY_SEPARATOR . $d;
            if (!is_dir($dir)) continue;
            if (glob($dir . DIRECTORY_SEPARATOR . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE)) $out[] = $d;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function active_name_from_path(string $p): string {
    $b = basename($p);
    $pos = strrpos($b, '_');
    return ($pos !== false) ? substr($b, $pos + 1) : $b;
}

/** Wendet Preset-Daten auf die übergebenen Variablen an. */
function apply_preset_data(array $pr, array &$map, bool &$hasHeader, bool &$skipEmpty, array &$cfg, int $maxCols): void {
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
    foreach ($map as $k => $v) {
        if ($v === '' || $v === null) continue;
        if ((int)$v < 0 || (int)$v >= $maxCols) unset($map[$k]);
    }
}

/** Zählt Datensätze, die tatsächlich gedruckt würden. Gibt null zurück wenn keine Zuordnung gesetzt. */
function count_labels_csv(string $csvPath, string $enc, string $delimiter, bool $hasHeader, bool $skipEmpty, array $map): ?int {
    if (count(array_filter($map, fn($v) => $v !== '' && $v !== null)) === 0) return null;
    try {
        $rows = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, null);
        if ($hasHeader && $rows) array_shift($rows);
        $count = 0;
        foreach ($rows as $row) {
            if ($skipEmpty) {
                $allEmpty = true;
                foreach ($map as $idx) {
                    if ($idx === '' || $idx === null) continue;
                    if (isset($row[(int)$idx]) && trim((string)$row[(int)$idx]) !== '') { $allEmpty = false; break; }
                }
                if ($allEmpty) continue;
            }
            $count++;
        }
        return $count;
    } catch (\Throwable $e) {
        return null;
    }
}

/* ---------- UI: Upload ---------- */

function render_upload_card(?string $activeName = null): void {
    echo '<div class="card shadow-sm mb-4">';
    if ($activeName) {
        echo '<div class="card-header d-flex align-items-center gap-2 py-2 bg-success bg-opacity-10 border-0 border-bottom border-success border-opacity-25">';
        echo '  <i class="fa-solid fa-circle-check text-success"></i>';
        echo '  <span class="text-muted small">Aktive Datei:</span>';
        echo '  <strong class="small">'.h($activeName).'</strong>';
        echo '  <span class="badge bg-success ms-1">geladen</span>';
        echo '  <span class="text-muted small ms-auto">Neue Datei unten auswählen um zu wechseln</span>';
        echo '</div>';
    }
    echo '  <div class="card-body p-4">';
    echo '    <form action="make-labels.php" method="post" enctype="multipart/form-data">';
    echo '      <div class="upload-zone">';
    echo '        <i class="fa-solid fa-file-arrow-up upload-icon"></i>';
    echo '        <div class="fw-semibold mb-1">CSV- oder Word-Datei auswählen</div>';
    echo '        <div class="text-muted small mb-3">.csv oder .docx</div>';
    echo '        <input class="form-control w-auto mx-auto" type="file" id="inputFile" name="input" required accept=".csv,.docx">';
    echo '      </div>';
    echo '      <div class="d-flex justify-content-between align-items-start mt-3 gap-3">';
    echo '        <button class="btn btn-primary btn-lg px-4" type="submit">';
    echo '          <i class="fa-solid fa-upload me-2"></i>Hochladen &amp; verarbeiten';
    echo '        </button>';
    echo '        <details>';
    echo '          <summary class="text-muted small" style="cursor:pointer;list-style:none;-webkit-appearance:none">';
    echo '            <i class="fa-solid fa-gear me-1"></i>Erweiterte Optionen';
    echo '          </summary>';
    echo '          <div class="mt-2">';
    echo '            <label for="delimiter" class="form-label small text-muted">Trennzeichen (Delimiter)</label>';
    echo '            <select id="delimiter" name="delimiter" class="form-select form-select-sm" style="width:210px">';
    echo '              <option value="">auto (empfohlen)</option>';
    echo '              <option value=";">Semikolon (;)</option>';
    echo '              <option value=",">Komma (,)</option>';
    echo '              <option value="\\t">Tab</option>';
    echo '              <option value="|">Pipe (|)</option>';
    echo '            </select>';
    echo '          </div>';
    echo '        </details>';
    echo '      </div>';
    echo '    </form>';
    echo '  </div>';
    echo '</div>';
    echo '<script>
(function(){
    var zone  = document.querySelector(".upload-zone");
    var input = document.getElementById("inputFile");
    if (!zone || !input) return;
    ["dragenter","dragover"].forEach(function(ev){
        zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.add("dragover"); });
    });
    zone.addEventListener("dragleave", function(e){
        if (!zone.contains(e.relatedTarget)) zone.classList.remove("dragover");
    });
    zone.addEventListener("drop", function(e){
        e.preventDefault();
        zone.classList.remove("dragover");
        var files = e.dataTransfer.files;
        if (!files.length) return;
        try { var dt = new DataTransfer(); dt.items.add(files[0]); input.files = dt.files; }
        catch(ex) { input.files = files; }
        input.closest("form").submit();
    });
    zone.style.cursor = "default";
})();
</script>';
}

/* ---------- UI: Mapping ---------- */

function render_mapping_form(array $args): void {
    [
        'csvPath'        => $csvPath,
        'enc'            => $enc,
        'delimiter'      => $delimiter,
        'cfg'            => $cfg,
        'sample'         => $sample,
        'maxCols'        => $maxCols,
        'hasHeader'      => $hasHeader,
        'map'            => $map,
        'skipEmpty'      => $skipEmpty,
        'message'        => $message,
        'selectedPreset' => $selectedPreset,
    ] = $args + ['selectedPreset' => '', 'message' => null];

    $presets       = load_presets();
    $defaultPreset = get_default_preset();
    $fields        = ['firstname'=>'Vorname','lastname'=>'Nachname','street'=>'Straße','zip'=>'PLZ','city'=>'Ort'];

    $cfg = array_merge(['top'=>10.0,'left'=>0.0,'pad'=>3.0,'hgap'=>0.0,'vgap'=>0.0,'fontsize'=>9.0,'font'=>'builtin:dejavusans'], $cfg ?? []);
    if (isset($cfg['font']) && !str_contains((string)$cfg['font'], ':')) $cfg['font'] = 'builtin:'.$cfg['font'];

    $labelCount = count_labels_csv($csvPath, $enc, $delimiter, $hasHeader, $skipEmpty, $map);

    if ($message) {
        echo '<div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2" role="alert">';
        echo '  <i class="fa-solid fa-circle-info flex-shrink-0"></i><span>'.h($message).'</span>';
        echo '  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }

    echo '<form method="post" action="make-labels.php" class="d-grid gap-3">';
    echo '<input type="hidden" name="stage"          value="mapping">';
    echo '<input type="hidden" name="csv_path"       value="'.h($csvPath).'">';
    echo '<input type="hidden" name="enc"            value="'.h($enc).'">';
    echo '<input type="hidden" name="delimiter"      value="'.h($delimiter).'">';
    echo '<input type="hidden" name="preset_overwrite" id="preset_overwrite" value="">';
    echo '<input type="hidden" name="confirm_delete"   id="confirm_delete"   value="">';

    /* ---- Presets ---- */
    echo '<div class="card shadow-sm">';
    echo '  <div class="card-header d-flex align-items-center justify-content-between py-2">';
    echo '    <span class="fw-semibold"><i class="fa-solid fa-sliders me-2 text-primary"></i>Preset</span>';
    if ($defaultPreset) {
        echo '  <span class="badge bg-warning text-dark"><i class="fa-solid fa-star me-1"></i>Standard: '.h($defaultPreset).'</span>';
    }
    echo '  </div>';
    echo '  <div class="card-body">';

    // Laden-Zeile
    echo '  <div class="row g-2 align-items-end mb-3">';
    echo '    <div class="col">';
    echo '      <select name="preset_select" id="preset_select" class="form-select">';
    echo '        <option value="">— Preset wählen —</option>';
    foreach ($presets as $p) {
        $sel  = ($selectedPreset === $p) ? ' selected' : '';
        $star = ($defaultPreset  === $p) ? ' ⭐' : '';
        echo '      <option value="'.h($p).'"'.$sel.'>'.h($p).$star.'</option>';
    }
    echo '      </select>';
    echo '    </div>';
    echo '    <div class="col-auto">';
    echo '      <button name="action" value="apply_preset" type="submit" class="btn btn-primary">Anwenden</button>';
    echo '    </div>';
    echo '    <div class="col-auto">';
    echo '      <button id="btn_set_default" name="action" value="set_default" type="submit" class="btn btn-outline-warning" title="Dieses Preset beim nächsten Datei-Upload automatisch anwenden">';
    echo '        <i class="fa-solid fa-star me-1"></i>Als Standard';
    echo '      </button>';
    echo '    </div>';
    echo '  </div>';

    // Speichern/Löschen-Zeile
    echo '  <div class="row g-2 align-items-end">';
    echo '    <div class="col">';
    echo '      <input type="text" name="preset_name" id="preset_name" class="form-control" placeholder="Name für neues Preset …">';
    echo '    </div>';
    echo '    <div class="col-auto">';
    echo '      <button id="btn_save_preset" name="action" value="save_preset" type="submit" class="btn btn-outline-success">Speichern</button>';
    echo '    </div>';
    echo '    <div class="col-auto">';
    echo '      <button id="btn_delete_preset" name="action" value="delete_preset" type="submit" class="btn btn-outline-danger">';
    echo        ($selectedPreset ? 'Löschen: '.h($selectedPreset) : 'Preset löschen');
    echo '      </button>';
    echo '    </div>';
    echo '  </div>';

    echo '  </div>'; // card-body
    echo '</div>'; // card

    /* ---- Accordion ---- */
    echo '<div class="accordion" id="accAdv">';

    // Einstellungen + Feineinstellungen
    echo '<div class="accordion-item">';
    echo '  <h2 class="accordion-header">';
    echo '    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clSet">';
    echo '      <i class="fa-solid fa-gear me-2 text-muted"></i>Einstellungen &amp; Feineinstellungen';
    echo '    </button>';
    echo '  </h2>';
    echo '  <div id="clSet" class="accordion-collapse collapse">';
    echo '    <div class="accordion-body">';
    echo '      <div class="d-flex gap-4 flex-wrap mb-3">';
    echo '        <div class="form-check"><input class="form-check-input" type="checkbox" id="has_header" name="has_header" value="1" '.($hasHeader?'checked':'').'>';
    echo '          <label class="form-check-label" for="has_header">Erste Zeile enthält Überschriften</label></div>';
    echo '        <div class="form-check"><input class="form-check-input" type="checkbox" id="skip_empty" name="skip_empty" value="1" '.($skipEmpty?'checked':'').'>';
    echo '          <label class="form-check-label" for="skip_empty">Leere Datensätze überspringen</label></div>';
    echo '      </div>';
    echo '      <hr class="my-3">';
    echo '      <p class="text-muted small mb-2">Druck-Feineinstellungen (Avery 3481, A4, 100 %, ohne Kopf-/Fußzeilen)</p>';
    echo '      <div class="row g-2">';
    $num = function($id,$label,$name,$val,$step) {
        echo '<div class="col-6 col-sm-4 col-md-2">';
        echo '  <label for="'.$id.'" class="form-label small">'.$label.'</label>';
        echo '  <input id="'.$id.'" name="'.$name.'" type="number" step="'.$step.'" class="form-control form-control-sm" value="'.h((string)$val).'">';
        echo '</div>';
    };
    $num('cfg_top',      'Top (mm)',         'cfg[top]',     $cfg['top'],      '0.1');
    $num('cfg_left',     'Left (mm)',        'cfg[left]',    $cfg['left'],     '0.1');
    $num('cfg_pad',      'Padding (mm)',     'cfg[pad]',     $cfg['pad'],      '0.1');
    $num('cfg_hgap',     'H-Gap (mm)',       'cfg[hgap]',    $cfg['hgap'],     '0.1');
    $num('cfg_vgap',     'V-Gap (mm)',       'cfg[vgap]',    $cfg['vgap'],     '0.1');
    $num('cfg_fontsize', 'Schriftgröße (pt)','cfg[fontsize]',$cfg['fontsize'], '0.5');
    $fontFolders = fonts_available();
    echo '        <div class="col-sm-6 col-md-4">';
    echo '          <label for="cfg_font" class="form-label small">Schrift</label>';
    echo '          <select id="cfg_font" name="cfg[font]" class="form-select form-select-sm">';
    $cur = (string)$cfg['font'];
    echo '            <option value="builtin:dejavusans"'.($cur==='builtin:dejavusans'?' selected':'').'>DejaVu Sans (eingebaut)</option>';
    foreach ($fontFolders as $f) {
        $val = 'folder:'.$f;
        echo '            <option value="'.h($val).'"'.($cur===$val?' selected':'').'>'.h($f).'</option>';
    }
    echo '          </select>';
    echo '        </div>';
    echo '      </div>'; // row g-2
    echo '    </div>'; // accordion-body
    echo '  </div>';
    echo '</div>';

    // Spalten-Zuordnung
    echo '<div class="accordion-item">';
    echo '  <h2 class="accordion-header">';
    echo '    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clMap">';
    echo '      <i class="fa-solid fa-table-columns me-2 text-muted"></i>Spalten-Zuordnung';
    echo '    </button>';
    echo '  </h2>';
    echo '  <div id="clMap" class="accordion-collapse collapse">';
    echo '    <div class="accordion-body">';
    echo '      <p class="text-muted small mb-3">Welche Spalte der CSV entspricht welchem Adressfeld?</p>';
    echo '      <div class="row g-3">';
    foreach ($fields as $key => $label) {
        $selectedIdx = isset($map[$key]) && $map[$key] !== '' ? (int)$map[$key] : null;
        echo '<div class="col-md-6 col-lg-4">';
        echo '  <label class="form-label small fw-semibold">'.h($label).'</label>';
        echo '  <select name="map['.$key.']" class="form-select form-select-sm">';
        echo '    <option value="">— nicht zuordnen —</option>';
        for ($i = 0; $i < $maxCols; $i++) {
            $example = '';
            foreach ($sample as $row) {
                if (isset($row[$i]) && trim((string)$row[$i]) !== '') { $example = (string)$row[$i]; break; }
            }
            $optLabel = 'Spalte '.($i+1).($example !== '' ? ' — '.mb_strimwidth($example,0,30,'…','UTF-8') : '');
            echo '    <option value="'.$i.'"'.($selectedIdx===$i?' selected':'').'>'.h($optLabel).'</option>';
        }
        echo '  </select>';
        echo '</div>';
    }
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // CSV-Vorschau
    if ($sample) {
        echo '<div class="accordion-item">';
        echo '  <h2 class="accordion-header">';
        echo '    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#clCsv">';
        echo '      <i class="fa-solid fa-eye me-2 text-muted"></i>CSV-Vorschau (erste 10 Zeilen)';
        echo '    </button>';
        echo '  </h2>';
        echo '  <div id="clCsv" class="accordion-collapse collapse">';
        echo '    <div class="accordion-body p-0">';
        echo '      <div class="table-responsive"><table class="table table-sm table-striped table-bordered mb-0"><thead><tr>';
        for ($i = 0; $i < $maxCols; $i++) echo '<th class="small text-nowrap">Spalte '.($i+1).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($sample as $r) {
            echo '<tr>';
            for ($i = 0; $i < $maxCols; $i++) echo '<td class="small">'.h($r[$i] ?? '').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
    }

    echo '</div>'; // accordion

    /* ---- Datensatz-Zahl + PDF-Button ---- */
    echo '<div class="generate-bar">';

    if ($labelCount !== null && $labelCount > 0) {
        echo '<div class="record-count">';
        echo '  <span class="record-count__number">'.number_format($labelCount, 0, ',', '.').'</span>';
        echo '  <span class="record-count__label">Datensätze werden gedruckt</span>';
        echo '</div>';
    } elseif ($labelCount === 0) {
        echo '<div class="text-warning d-flex align-items-center gap-2">';
        echo '  <i class="fa-solid fa-triangle-exclamation"></i>';
        echo '  <span>Keine Datensätze mit aktuellem Mapping</span>';
        echo '</div>';
    } else {
        echo '<div class="text-muted small d-flex align-items-center gap-2">';
        echo '  <i class="fa-solid fa-circle-info"></i>';
        echo '  <span>Preset anwenden oder Zuordnung einrichten</span>';
        echo '</div>';
    }

    echo '  <button name="action" value="generate" type="submit" class="btn btn-success btn-lg px-5">';
    echo '    <i class="fa-solid fa-file-pdf me-2"></i>PDF erzeugen';
    echo '  </button>';
    echo '</div>';

    echo '<script>
document.getElementById("btn_save_preset").addEventListener("click",function(ev){
  var sel=document.getElementById("preset_select").value;
  var name=document.getElementById("preset_name").value.trim();
  if(sel&&!name){
    if(confirm("Preset \\""+sel+"\\" überschreiben?")){
      document.getElementById("preset_overwrite").value="1";
    }else{ev.preventDefault();}
  }
});
document.getElementById("btn_delete_preset").addEventListener("click",function(ev){
  var sel=document.getElementById("preset_select").value;
  if(!sel){ev.preventDefault();alert("Kein Preset gewählt.");return;}
  if(confirm("Preset \\""+sel+"\\" wirklich löschen?")){
    document.getElementById("confirm_delete").value="1";
  }else{ev.preventDefault();}
});
document.getElementById("preset_select").addEventListener("change",function(){
  var sel=this.value;
  var btn=document.getElementById("btn_delete_preset");
  if(btn) btn.textContent=sel?"Löschen: "+sel:"Preset löschen";
  if(sel) document.querySelector("[name='action'][value='apply_preset']").click();
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
    $enc       = $_POST['enc']      ?? 'UTF-8';
    $delimiter = $_POST['delimiter']?? ';';
    $cfg       = $_POST['cfg']      ?? [];
    $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
    $skipEmpty = isset($_POST['skip_empty']) && $_POST['skip_empty'] === '1';
    $map       = $_POST['map']      ?? [];
    $selectedPreset = $_POST['preset_select'] ?? '';

    try {
        if (!$csvPath || !is_file($csvPath) || !tmp_ok($csvPath)) {
            throw new RuntimeException('CSV nicht verfügbar. Bitte neu hochladen.');
        }

        $sample  = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);
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
            $vname  = $get('firstname');
            $nname  = $get('lastname');
            $street = $get('street');
            $zip    = $get('zip');
            $city   = $get('city');

            $allEmpty = ($vname===''&&$nname===''&&$street===''&&$zip===''&&$city==='');
            if ($skipEmpty && $allEmpty) continue;

            $line1  = trim($vname.' '.$nname);
            $line2  = $street;
            $line3  = trim($zip.' '.$city);
            $lines  = array_values(array_filter([$line1,$line2,$line3], fn($x) => $x !== ''));
            if (!$skipEmpty && $allEmpty) $lines = [];

            $labels[] = $lines;
        }

        if (!$labels) {
            $pageTitle = 'LabelMaker';
            require __DIR__ . '/partials/header.php';
            render_upload_card(active_name_from_path($csvPath));
            render_mapping_form([
                'csvPath'=>$csvPath,'enc'=>$enc,'delimiter'=>$delimiter,
                'cfg'=>$cfg,'sample'=>$sample,'maxCols'=>$maxCols,
                'hasHeader'=>$hasHeader,'map'=>$map,'skipEmpty'=>$skipEmpty,
                'message'=>'Keine Etiketten nach aktuellem Mapping.','selectedPreset'=>$selectedPreset,
            ]);
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        $lp = [
            'topMargin'  => (float)($cfg['top']      ?? 10.0),
            'leftMargin' => (float)($cfg['left']     ?? 0.0),
            'padX'       => (float)($cfg['pad']      ?? 3.0),
            'padY'       => (float)($cfg['pad']      ?? 3.0),
            'hGap'       => (float)($cfg['hgap']     ?? 0.0),
            'vGap'       => (float)($cfg['vgap']     ?? 0.0),
            'fontSize'   => (float)($cfg['fontsize'] ?? 9.0),
            'font'       => (string)($cfg['font']    ?? 'builtin:dejavusans'),
        ];

        $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('etiketten_', true) . '.pdf';
        (new LabelPdf($lp))->generateAvery3481($labels, $pdfPath);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Adress-Etiketten-'.date('Y-m-d_H-i').'.pdf"');
        header('Content-Length: '.(string)filesize($pdfPath));
        header('X-Content-Type-Options: nosniff');
        readfile($pdfPath);
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fehler: '.$e->getMessage();
    } finally {
        if (isset($pdfPath) && is_file($pdfPath)) @unlink($pdfPath);
    }
    exit;
}

/* ---------- Controller (UI) ---------- */
$pageTitle = 'LabelMaker';
require __DIR__ . '/partials/header.php';

/* Stage A: Upload → Mapping */
if (isset($_FILES['input']) && $_FILES['input']['error'] === UPLOAD_ERR_OK) {
    $origName = $_FILES['input']['name'] ?? 'input';
    $upload   = upload_dir() . DIRECTORY_SEPARATOR . uniqid('label_', true) . '_' . basename($origName);
    if (!move_uploaded_file($_FILES['input']['tmp_name'], $upload)) {
        render_upload_card();
        echo '<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark me-2"></i>Konnte Upload nicht speichern.</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    $cfg             = ['top'=>10.0,'left'=>0.0,'pad'=>3.0,'hgap'=>0.0,'vgap'=>0.0,'fontsize'=>9.0];
    $forcedDelimiter = $_POST['delimiter'] ?? null;

    try {
        $csvPath   = CsvUtil::ensureCsv($upload);
        $enc       = CsvUtil::detectEncoding($csvPath);
        $delimiter = CsvUtil::detectDelimiter($csvPath, $enc, $forcedDelimiter);
        $sample    = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);
        $maxCols   = 0; foreach ($sample as $r) $maxCols = max($maxCols, count($r));

        $hasHeaderDefault = false;
        if ($sample && count($sample) >= 2) {
            $allAlpha = fn($row) => count(array_filter($row, fn($c) => preg_match('/[A-Za-zÄÖÜäöüß]/u', (string)$c))) >= min(2, max(1, (int)floor(count($row)/2)));
            $hasHeaderDefault = $allAlpha($sample[0]) && !$allAlpha($sample[1]);
        }

        $map            = [];
        $skipEmpty      = true;
        $selectedPreset = '';
        $autoMessage    = null;

        // Standard-Preset automatisch anwenden
        $defaultPreset = get_default_preset();
        if ($defaultPreset && ($pr = read_preset($defaultPreset))) {
            apply_preset_data($pr, $map, $hasHeaderDefault, $skipEmpty, $cfg, $maxCols);
            $selectedPreset = $defaultPreset;
            $autoMessage    = "Standard-Preset '{$defaultPreset}' automatisch angewendet.";
        }

        render_upload_card($origName);
        render_mapping_form([
            'csvPath'        => $csvPath,
            'enc'            => $enc,
            'delimiter'      => $delimiter,
            'cfg'            => $cfg,
            'sample'         => $sample,
            'maxCols'        => $maxCols,
            'hasHeader'      => $hasHeaderDefault,
            'map'            => $map,
            'skipEmpty'      => $skipEmpty,
            'message'        => $autoMessage,
            'selectedPreset' => $selectedPreset,
        ]);
        require __DIR__ . '/partials/footer.php';
        exit;
    } catch (Throwable $e) {
        render_upload_card();
        echo '<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark me-2"></i>Fehler: '.h($e->getMessage()).'</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }
}

/* Stage Mapping */
$stage          = $_POST['stage']         ?? '';
$action         = $_POST['action']        ?? '';
$csvPath        = $_POST['csv_path']      ?? '';
$enc            = $_POST['enc']           ?? 'UTF-8';
$delimiter      = $_POST['delimiter']     ?? ';';
$cfg            = $_POST['cfg']           ?? [];
$hasHeader      = isset($_POST['has_header']) && $_POST['has_header'] === '1';
$skipEmpty      = isset($_POST['skip_empty']) && $_POST['skip_empty'] === '1';
$map            = $_POST['map']           ?? [];
$selectedPreset = $_POST['preset_select'] ?? '';

if ($stage === 'mapping') {
    if (!$csvPath || !is_file($csvPath) || !tmp_ok($csvPath)) {
        render_upload_card();
        echo '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-2"></i>Die hochgeladene CSV ist nicht mehr verfügbar. Bitte neu hochladen.</div>';
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    $sample  = CsvUtil::readRowsRaw($csvPath, $enc, $delimiter, 10);
    $maxCols = 0; foreach ($sample as $r) $maxCols = max($maxCols, count($r));
    render_upload_card(active_name_from_path($csvPath));

    $renderArgs = compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty');

    if ($action === 'apply_preset') {
        $sel = $_POST['preset_select'] ?? '';
        $pr  = $sel ? read_preset($sel) : null;
        if ($pr) {
            apply_preset_data($pr, $map, $hasHeader, $skipEmpty, $cfg, $maxCols);
            $msg = "Preset '{$sel}' angewendet.";
        } else {
            $msg = 'Kein gültiges Preset gewählt.';
        }
        render_mapping_form(compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty') + ['message'=>$msg,'selectedPreset'=>$sel]);
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    if ($action === 'set_default') {
        $sel = $_POST['preset_select'] ?? '';
        if ($sel && in_array($sel, load_presets(), true)) {
            save_default_preset($sel);
            $msg = "'{$sel}' ist jetzt das Standard-Preset – wird beim naechsten Upload automatisch angewendet.";
        } elseif ($sel === '') {
            save_default_preset('');
            $msg = 'Standard-Preset entfernt.';
        } else {
            $msg = 'Kein gültiges Preset gewählt.';
        }
        render_mapping_form(compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty') + ['message'=>$msg,'selectedPreset'=>$sel]);
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    if ($action === 'save_preset') {
        $sel      = $_POST['preset_select']    ?? '';
        $rawName  = trim((string)($_POST['preset_name'] ?? ''));
        $overwrite = ($_POST['preset_overwrite'] ?? '') === '1';

        if ($rawName === '' && $sel !== '') {
            if (!$overwrite) {
                render_mapping_form($renderArgs + ['message'=>"Soll '{$sel}' ueberschrieben werden? Erneut auf Speichern klicken und bestaetigen.",'selectedPreset'=>$sel]);
                require __DIR__ . '/partials/footer.php';
                exit;
            }
            $name = sanitize_preset($sel);
        } else {
            if ($rawName === '') {
                render_mapping_form($renderArgs + ['message'=>'Kein Presetname angegeben.','selectedPreset'=>$sel]);
                require __DIR__ . '/partials/footer.php';
                exit;
            }
            $name = sanitize_preset($rawName);
        }

        $hasAny = false;
        foreach ($map as $v) if ($v !== '' && $v !== null) { $hasAny = true; break; }
        if (!$hasAny) {
            $msg = 'Kein Feld zugeordnet – Preset nicht gespeichert.';
            $name = $sel;
        } else {
            $cfgSave = [];
            foreach (['top','left','pad','hgap','vgap','fontsize','font'] as $k) {
                if (!array_key_exists($k, $cfg)) continue;
                $cfgSave[$k] = ($k === 'font') ? (string)$cfg[$k] : (float)$cfg[$k];
            }
            save_preset($name, ['map'=>$map,'has_header'=>$hasHeader,'skip_empty'=>$skipEmpty,'cfg'=>$cfgSave,'ts'=>time()]);
            $msg = "Preset '{$name}' gespeichert.";
        }
        render_mapping_form(compact('csvPath','enc','delimiter','cfg','sample','maxCols','hasHeader','map','skipEmpty') + ['message'=>$msg,'selectedPreset'=>$name]);
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    if ($action === 'delete_preset') {
        $sel       = $_POST['preset_select']  ?? '';
        $confirmed = ($_POST['confirm_delete'] ?? '') === '1';

        if ($sel === '') {
            render_mapping_form($renderArgs + ['message'=>'Kein Preset gewählt.','selectedPreset'=>'']);
            require __DIR__ . '/partials/footer.php';
            exit;
        }
        if (!$confirmed) {
            render_mapping_form($renderArgs + ['message'=>'Löschen bestätigen.','selectedPreset'=>$sel]);
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        $ok  = delete_preset($sel);
        $msg = $ok ? "Preset '{$sel}' geloescht." : "Preset '{$sel}' nicht gefunden.";
        // Standard-Preset zurücksetzen falls gelöscht
        if ($ok && get_default_preset() === $sel) save_default_preset('');
        render_mapping_form($renderArgs + ['message'=>$msg,'selectedPreset'=>'']);
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    // Kein passender Action → Formular nochmal anzeigen
    render_mapping_form($renderArgs + ['message'=>null,'selectedPreset'=>$selectedPreset]);
    require __DIR__ . '/partials/footer.php';
    exit;
}

/* Initial GET */
render_upload_card();
require __DIR__ . '/partials/footer.php';

<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'LabelMaker';
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="vendor/fontawesome-free-7.0.1-web/css/all.css" rel="stylesheet">


    <link href="assets/css/styles.css" rel="stylesheet">
    <style>body{background:#f4f6f9}</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container" style="max-width:860px">
        <span class="navbar-brand mb-0 h1"><i class="fa-solid fa-tags me-2"></i>LabelMaker</span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-secondary small d-none d-sm-inline">Avery Zweckform 3481 &middot; CSV &rarr; PDF</span>
            <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                <input class="form-check-input" type="checkbox" id="easyToggle" role="switch" style="cursor:pointer">
                <label class="form-check-label text-white-50 small" for="easyToggle">Easy Mode</label>
            </div>
        </div>
    </div>
</nav>
<main class="container my-4" style="max-width:860px">
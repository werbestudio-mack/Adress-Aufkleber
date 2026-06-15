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
    <div class="container">
        <span class="navbar-brand mb-0 h1"><i class="fa-solid fa-tags me-2"></i>LabelMaker</span>
        <span class="text-secondary small">Avery Zweckform 3481 · CSV → PDF</span>
    </div>
</nav>
<main class="container my-4" style="max-width:860px">
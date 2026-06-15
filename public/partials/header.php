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
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <span class="navbar-brand mb-0 h1">LabelMaker</span>
    </div>
</nav>
<main class="container my-4">
<?php
/**
 * Alert Component
 */
function renderAlert($message, $type = 'info', $dismissible = true) {
    $closeBtn = $dismissible ? '<button class="close-btn" onclick="this.parentElement.remove()">×</button>' : '';
    
    return <<<HTML
    <div class="alert alert-{$type}">
        <div class="flex-between">
            <span>{$message}</span>
            {$closeBtn}
        </div>
    </div>
    HTML;
}

/**
 * Card Component
 */
function renderCard($title, $content, $footer = '') {
    $footerHTML = $footer ? "<div class=\"card-footer\">{$footer}</div>" : '';
    
    return <<<HTML
    <div class="card">
        <div class="card-header">
            <h3 class="mb-0">{$title}</h3>
        </div>
        <div class="card-body">
            {$content}
        </div>
        {$footerHTML}
    </div>
    HTML;
}

/**
 * Form Group Component
 */
function renderFormGroup($name, $label, $type = 'text', $value = '', $required = false, $options = []) {
    $requiredAttr = $required ? 'required' : '';
    $requiredLabel = $required ? '<span class="form-required">*</span>' : '';
    
    $fieldHTML = '';
    
    if ($type === 'select') {
        $optionsHTML = '';
        foreach ($options as $optVal => $optLabel) {
            $selected = $value === $optVal ? 'selected' : '';
            $optionsHTML .= "<option value=\"{$optVal}\" {$selected}>{$optLabel}</option>";
        }
        $fieldHTML = "<select name=\"{$name}\" {$requiredAttr}>{$optionsHTML}</select>";
    } elseif ($type === 'textarea') {
        $fieldHTML = "<textarea name=\"{$name}\" {$requiredAttr}>{$value}</textarea>";
    } else {
        $fieldHTML = "<input type=\"{$type}\" name=\"{$name}\" value=\"{$value}\" {$requiredAttr}>";
    }
    
    return <<<HTML
    <div class="form-group">
        <label for="{$name}">{$label} {$requiredLabel}</label>
        {$fieldHTML}
    </div>
    HTML;
}

/**
 * Table Component
 */
function renderTable($headers, $rows) {
    $headerHTML = '';
    foreach ($headers as $header) {
        $headerHTML .= "<th>{$header}</th>";
    }
    
    $rowsHTML = '';
    foreach ($rows as $row) {
        $rowsHTML .= "<tr>";
        foreach ($row as $cell) {
            $rowsHTML .= "<td>{$cell}</td>";
        }
        $rowsHTML .= "</tr>";
    }
    
    return <<<HTML
    <table class="table">
        <thead>
            <tr>{$headerHTML}</tr>
        </thead>
        <tbody>
            {$rowsHTML}
        </tbody>
    </table>
    HTML;
}

/**
 * Modal Component
 */
function renderModal($id, $title, $content, $footer = '') {
    $footerHTML = $footer ? "<div class=\"modal-footer\">{$footer}</div>" : '';
    
    return <<<HTML
    <div id="{$id}" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">{$title}</h2>
                <button class="modal-close">×</button>
            </div>
            <div class="modal-body">
                {$content}
            </div>
            {$footerHTML}
        </div>
    </div>
    HTML;
}

/**
 * Badge Component
 */
function renderBadge($text, $type = 'info') {
    return "<span class=\"badge badge-{$type}\">{$text}</span>";
}

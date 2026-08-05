<?php
$flash = getFlash();
if ($flash):
?>
<div class="flash-wrap mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4 mb-0 animate-slide-up">
    <div class="flash-card max-w-7xl mx-auto flex items-start gap-3 p-4 rounded-premium border shadow-md alert-auto-dismiss
        <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : '' ?>
        <?= $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-800' : '' ?>
        <?= $flash['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-800' : '' ?>
        <?= $flash['type'] === 'info' ? 'bg-blue-50 border-blue-200 text-blue-800' : '' ?>
    ">
        <span class="flex-shrink-0 mt-0.5">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'circle-check' : ($flash['type'] === 'error' ? 'circle-xmark' : ($flash['type'] === 'warning' ? 'triangle-exclamation' : 'circle-info')) ?>"></i>
        </span>
        <div class="flex-1 min-w-0 text-sm"><?= $flash['message'] ?></div>
        <button type="button" onclick="this.parentElement.remove()" class="flex-shrink-0 opacity-60 hover:opacity-100 transition-opacity text-xs p-1" aria-label="Close">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
</div>
<?php endif; ?>

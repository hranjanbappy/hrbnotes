<?php
/**
 * Recursive sidebar tree renderer.
 * Expects $tree (array of nodes) in scope. Define the renderer once.
 */

if (!function_exists('render_tree_nodes')) {
    function render_tree_nodes(array $nodes): void
    {
        echo '<ul class="tree-list">';
        foreach ($nodes as $node) {
            if ($node['type'] === 'folder') {
                echo '<li class="tree-folder">';
                echo '<details><summary class="d-flex align-items-center justify-content-between">';
                echo '<span><span class="ico">&#128193;</span>' . Security::e($node['name']) . '</span>';
                if (Auth::isAdmin()) {
                    echo '<button class="btn-delete-folder btn btn-link p-0 text-danger border-0 opacity-50 hover-opacity-100" '
                        . 'data-folder="' . Security::e($node['path']) . '" '
                        . 'title="Delete folder and all its contents" '
                        . 'style="font-size: 0.8rem; line-height: 1; text-decoration: none; min-height: unset;">&#128465;</button>';
                }
                echo '</summary>';
                render_tree_nodes($node['children']);
                echo '</details></li>';
            } else {
                $url = BASE_URL . '/?route=workspace&path=' . urlencode($node['path']);
                $label = $node['title'] !== '' ? $node['title'] : $node['name'];
                echo '<li class="tree-note">'
                    . '<a href="' . Security::e($url) . '" data-path="' . Security::e($node['path']) . '" class="note-link">'
                    . '<span class="ico">&#128196;</span>' . Security::e($label) . '</a></li>';
            }
        }
        echo '</ul>';
    }
}
?>
<div class="vault-tree">
    <div class="tree-head">
        <span>VAULT</span>
        <div class="d-flex align-items-center gap-1">
            <button id="btnRescan" class="btn btn-sm btn-outline-secondary" title="Rescan vault">&#8635;</button>
            <label class="btn btn-sm btn-outline-secondary mb-0 px-1" title="Import vault ZIP" style="cursor:pointer">
                &#8679;
                <input type="file" id="vaultImportInput" hidden accept=".zip">
            </label>
            <a id="btnExportVault" href="<?= BASE_URL ?>/?route=vault.export"
               class="btn btn-sm btn-outline-secondary px-1" title="Export vault as ZIP">&#8681;</a>
        </div>
    </div>
    <div id="vaultActionStatus" class="small px-2 py-1 text-muted" style="display:none"></div>
    <div id="rescanStatus" class="small px-2 py-1 text-muted" style="display:none"></div>
    <div id="vaultTreeNodes" data-is-admin="<?= Auth::isAdmin() ? '1' : '0' ?>">
    <?php if (empty($tree)): ?>
        <p class="text-muted small px-2 py-2">No notes yet. Add .md files to the vault, import a ZIP, or rescan.</p>
    <?php else: ?>
        <?php render_tree_nodes($tree); ?>
    <?php endif; ?>
    </div>
</div>

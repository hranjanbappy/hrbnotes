<?php /** Workspace: viewer + editor. */ ?>
<div class="app-shell" id="workspace" data-initial-path="<?= Security::e($initialPath) ?>">

    <aside class="sidebar" id="leftSidebar">
        <?php require APP_PATH . '/views/partials/_tree.php'; ?>
    </aside>

    <section class="content-area note-pane">
        <!-- Main toolbar -->
        <div class="note-toolbar">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button id="btnToggleLeft" class="btn btn-sm btn-outline-secondary panel-toggle"
                        title="Toggle vault sidebar">&#9776;</button>
                <div class="note-breadcrumb" id="noteBreadcrumb">
                    <span class="text-muted">Select a note from the sidebar</span>
                </div>
            </div>
            <div class="note-actions">
                <span id="saveStatus" class="save-status"></span>
                <button id="btnView" class="btn btn-sm btn-outline-secondary active" title="View"><i class="fas fa-eye"></i></button>
                <button id="btnEdit" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-pencil-alt"></i></button>
                <button id="btnSave" class="btn btn-sm btn-primary" disabled title="Save"><i class="fas fa-save"></i></button>
                <button id="btnNew"  class="btn btn-sm btn-success" title="New Note"><i class="fas fa-plus"></i></button>
                <button id="btnDelete" class="btn btn-sm btn-outline-danger" title="Delete note"><i class="fas fa-trash"></i></button>
                <button id="btnToggleRight" class="btn btn-sm btn-outline-secondary panel-toggle d-none d-lg-inline-flex"
                        title="Toggle info panel">&#9776;</button>
            </div>
        </div>



        <!-- Viewer -->
        <article id="noteView" class="markdown-body"></article>

        <!-- Editor -->
        <div id="noteEditor" class="note-editor d-none">
            <div class="editor-tools py-1 mb-1">
                <input type="file" id="uploadInput" hidden
                       accept=".png,.jpg,.jpeg,.gif,.webp,.svg,.pdf,.zip">
                <span class="small text-muted" id="uploadStatus"></span>
            </div>
            <textarea id="editorArea"></textarea>
        </div>
    </section>

    <!-- Right metadata rail -->
    <aside class="meta-rail" id="rightRail">
        <div class="panel">
            <h6 class="panel-title">Tags</h6>
            <div id="metaTags" class="tag-cloud"><span class="text-muted small">&#8212;</span></div>
        </div>
        <div class="panel">
            <h6 class="panel-title">Backlinks</h6>
            <ul id="metaBacklinks" class="list-flush"><li class="text-muted small">&#8212;</li></ul>
        </div>
        <div class="panel">
            <h6 class="panel-title">Outgoing links</h6>
            <ul id="metaOutlinks" class="list-flush"><li class="text-muted small">&#8212;</li></ul>
        </div>
        <div class="panel">
            <h6 class="panel-title">Info</h6>
            <div id="metaInfo" class="small text-muted">&#8212;</div>
        </div>
    </aside>
</div>



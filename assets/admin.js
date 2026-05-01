document.addEventListener('DOMContentLoaded', function () {
    
    // --- 1. UI Notice Generator ---
    function showGdmNotice(type, message) {
        // Remove existing notices if they exist
        const existingNotice = document.querySelector('.gdm-dynamic-notice');
        if (existingNotice) existingNotice.remove();

        const noticeDiv = document.createElement('div');
        noticeDiv.className = `notice notice-${type} is-dismissible gdm-dynamic-notice`;
        noticeDiv.innerHTML = `<p>${message}</p>`;

        const title = document.querySelector('.gdm-wrap h1');
        if (title) {
            title.insertAdjacentElement('afterend', noticeDiv);
        }
        
        // Auto-dismiss success notices after 5 seconds
        if(type === 'success') {
            setTimeout(() => noticeDiv.remove(), 5000);
        }
    }

    // --- 2. Ajax Deployments (No Page Reload) ---
    const deployButtons = document.querySelectorAll('.gdm-deploy-btn');
    deployButtons.forEach(button => {
        button.addEventListener('click', async function (e) {
            e.preventDefault();

            if (!confirm(gdmAjax.i18n.confirmDeploy)) return;

            const packageId = this.getAttribute('data-package');
            const originalText = this.innerHTML;
            const tableRow = this.closest('tr');

            this.disabled = true;
            this.innerHTML = '<span class="dashicons dashicons-update" style="animation: gdm-spin 2s infinite linear;"></span> ' + gdmAjax.i18n.deploying;

            const formData = new FormData();
            formData.append('action', 'gdm_ajax_deploy');
            formData.append('package_id', packageId);
            formData.append('_ajax_nonce', gdmAjax.nonce);

            try {
                const response = await fetch(gdmAjax.ajax_url, { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showGdmNotice('success', data.data || gdmAjax.i18n.success);
                    
                    this.innerHTML = '✅ ' + gdmAjax.i18n.success;
                    this.classList.remove('button-secondary');
                    this.classList.add('button-primary');

                    // Dynamically update the row instead of reloading the page
                    if (tableRow) {
                        const healthCell = tableRow.cells[4];
                        const dateCell = tableRow.cells[6];
                        if (healthCell) {
                            healthCell.innerHTML = '<span class="gdm-badge gdm-badge--success">Healthy</span><div class="description" style="margin-top: 4px; font-size: 11px; max-width: 150px;">Deployed successfully.</div>';
                        }
                        if (dateCell) {
                            dateCell.innerHTML = '<strong class="gdm-text-strong">Just now</strong>';
                        }
                    }

                    // Reset button after 3 seconds so they can deploy again if needed
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.classList.remove('button-primary');
                        this.classList.add('button-secondary');
                        this.disabled = false;
                    }, 3000);

                } else {
                    showGdmNotice('error', data.data || gdmAjax.i18n.error);
                    this.innerHTML = originalText;
                    this.disabled = false;
                }
            } catch (error) {
                showGdmNotice('error', gdmAjax.i18n.error);
                this.innerHTML = originalText;
                this.disabled = false;
            }
        });
    });

    // --- 3. Modal Focus Trapping Utility ---
    function openModal(modalEl, inputToFocus = null) {
        modalEl.style.display = 'flex';
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden'; // Lock background scrolling
        if (inputToFocus) {
            inputToFocus.focus();
            if (inputToFocus.select) inputToFocus.select();
        }
    }

    function closeModal(modalEl) {
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = ''; // Unlock background scrolling
    }

    // --- 4. Webhook URL Modal Logic ---
    const urlModal = document.getElementById('gdm-url-modal');
    const urlField = document.getElementById('gdm-url-field');
    const closeUrlBtn = document.getElementById('gdm-close-url-modal');
    const copyUrlBtn = document.getElementById('gdm-copy-url');
    const viewButtons = document.querySelectorAll('.gdm-view-url');

    if (urlModal && urlField && closeUrlBtn && copyUrlBtn && viewButtons.length) {
        viewButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                urlField.value = btn.getAttribute('data-url') || '';
                openModal(urlModal, urlField);
            });
        });

        closeUrlBtn.addEventListener('click', () => closeModal(urlModal));
        urlModal.addEventListener('click', e => { if (e.target === urlModal) closeModal(urlModal); });

        copyUrlBtn.addEventListener('click', function () {
            urlField.select();
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(urlField.value).then(() => {
                    copyUrlBtn.textContent = 'Copied!';
                    setTimeout(() => copyUrlBtn.textContent = 'Copy URL', 1500);
                });
            } else {
                document.execCommand('copy');
                copyUrlBtn.textContent = 'Copied!';
                setTimeout(() => copyUrlBtn.textContent = 'Copy URL', 1500);
            }
        });
    }

    // --- 5. GitHub Repo Auto-fill Wizard Logic ---
    const fetchBtn = document.getElementById('gdm-fetch-repos-btn');
    const repoModalOverlay = document.getElementById('gdm-repo-modal-overlay');
    const closeRepoModalBtn = document.getElementById('gdm-close-repo-modal');
    const repoList = document.getElementById('gdm-repo-list');
    const searchInput = document.getElementById('gdm-repo-search');
    const msgEl = document.getElementById('gdm-repo-modal-msg');
    const branchSelect = document.getElementById('gdm-branch-select');
    const branchInput = document.getElementById('branch');
    let allRepos = [];

    if (fetchBtn && repoModalOverlay) {
        fetchBtn.addEventListener('click', async function() {
            openModal(repoModalOverlay, searchInput);
            msgEl.textContent = 'Fetching your repositories from GitHub...';
            repoList.style.display = 'none';
            searchInput.style.display = 'none';
            repoList.innerHTML = '';

            const formData = new FormData();
            formData.append('action', 'gdm_ajax_get_repos');
            formData.append('_ajax_nonce', gdmAjax.nonce);

            try {
                const response = await fetch(gdmAjax.ajax_url, { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success && data.data && data.data.length > 0) {
                    allRepos = data.data;
                    msgEl.textContent = 'Select a repository:';
                    renderRepoList(allRepos);
                    repoList.style.display = 'block';
                    searchInput.style.display = 'block';
                    searchInput.focus();
                } else {
                    msgEl.textContent = data.data || 'No repositories found or token lacks access.';
                }
            } catch (error) {
                msgEl.textContent = 'Error fetching repositories.';
            }
        });

        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase();
                const filtered = allRepos.filter(r => r.full_name.toLowerCase().includes(term));
                renderRepoList(filtered);
            });
        }

        function renderRepoList(repos) {
            repoList.innerHTML = '';
            repos.forEach(repo => {
                const item = document.createElement('div');
                item.className = 'gdm-repo-item';
                item.innerHTML = `<strong>${repo.full_name}</strong><span style="font-size:11px; color:#666;">${repo.private ? '🔒 Private' : '🌎 Public'}</span>`;
                item.addEventListener('click', () => selectRepo(repo));
                repoList.appendChild(item);
            });
        }

        async function selectRepo(repo) {
            document.getElementById('name').value = repo.name;
            document.getElementById('repo_owner').value = repo.owner;
            document.getElementById('repo_name').value = repo.name;
            document.getElementById('plugin_slug').value = repo.name;
            
            closeModal(repoModalOverlay);
            
            // Fetch branches
            if (branchSelect && branchInput) {
                branchSelect.innerHTML = '<option>Loading branches...</option>';
                branchSelect.style.display = 'block';
                branchInput.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'gdm_ajax_get_branches');
                formData.append('owner', repo.owner);
                formData.append('repo', repo.name);
                formData.append('_ajax_nonce', gdmAjax.nonce);

                try {
                    const response = await fetch(gdmAjax.ajax_url, { method: 'POST', body: formData });
                    const data = await response.json();

                    if (data.success && data.data && data.data.length > 0) {
                        branchSelect.innerHTML = '';
                        data.data.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b;
                            opt.textContent = b;
                            if(b === 'main' || b === 'master') opt.selected = true;
                            branchSelect.appendChild(opt);
                        });
                        branchInput.value = branchSelect.value;
                        
                        branchSelect.addEventListener('change', function() {
                            branchInput.value = this.value;
                        });
                    } else {
                        branchSelect.style.display = 'none';
                        branchInput.style.display = 'block';
                    }
                } catch (e) {
                    branchSelect.style.display = 'none';
                    branchInput.style.display = 'block';
                }
            }
        }

        closeRepoModalBtn.addEventListener('click', () => closeModal(repoModalOverlay));
        repoModalOverlay.addEventListener('click', e => { if (e.target === repoModalOverlay) closeModal(repoModalOverlay); });
    }

    // --- 6. Escape Key Listener for all Modals ---
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (urlModal && urlModal.style.display === 'flex') closeModal(urlModal);
            if (repoModalOverlay && repoModalOverlay.style.display === 'flex') closeModal(repoModalOverlay);
        }
    });

    // --- 7. Link Existing Package Select Logic ---
    const linkSelect = document.getElementById('installed_package');
    const linkNameInput = document.getElementById('link_name');
    if (linkSelect && linkNameInput) {
        linkSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option && option.value !== '') {
                linkNameInput.value = option.getAttribute('data-name') || '';
            } else {
                linkNameInput.value = '';
            }
        });
    }
});

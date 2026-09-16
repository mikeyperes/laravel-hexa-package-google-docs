window.googleDocsSettings = function () {
    const configElement = document.getElementById("google-docs-settings-config");
    const config = configElement ? JSON.parse(configElement.textContent || "{}") : {};

    return {
        accounts: config.accounts || [],
        accountId: config.accountId || 'legacy',
        defaultAccountId: config.defaultAccountId || 'legacy',
        newAccountEmail: '',
        accountResult: null,
        accountResults: {},
        savingAccount: false,
        savingDefaultAccountId: null,
        testingAccountId: null,
        smokingAccountId: null,
        refreshingToken: false,
        savingGeneral: false,
        savingOauth: false,
        savingServiceAccount: false,
        testingRead: false,
        testingWrite: false,
        smokingWrite: false,
        creatingFolder: false,
        generalResult: null,
        oauthResult: null,
        serviceAccountResult: null,
        readResult: null,
        writeResult: null,
        folderResult: null,
        context: { ...(config.context || {}) },
        general: { ...(config.general || {}) },
        oauth: { oauth_client_id: '', oauth_client_secret: '', oauth_refresh_token: '' },
        serviceAccount: { service_account_json: '' },
        folder: { folder_name: "scalemypublication.com, publish exports", parent_folder_id: "", set_as_default: true },
        read: { doc_url: "", format: config.defaultFormat || "txt" },

        init() {},

        selectedAccount() {
            return this.accounts.find((account) => account.id === this.accountId) || {};
        },

        selectedAccountLabel() {
            const account = this.selectedAccount();
            return account.connected_email || account.label || 'Selected Google account';
        },

        accountEmail(account) {
            return account.connected_email || account.label || 'Unnamed Google account';
        },

        authModeLabel(mode) {
            return {
                oauth_user: 'OAuth user',
                service_account: 'Service account',
                public_read: 'Public read only',
            }[mode] || mode;
        },

        manageAccount(accountId) {
            const url = new URL(window.location.href);
            url.searchParams.set('account_id', accountId);
            url.hash = 'account-settings';
            window.location.assign(url.toString());
        },

        selectAccount() {
            this.manageAccount(this.accountId);
        },

        refreshToken() {
            this.refreshingToken = true;
            const url = new URL(config.routes.oauthRedirect, window.location.origin);
            url.searchParams.set('account_id', this.accountId);
            window.location.assign(url.toString());
        },

        updateAccount(accountId, changes) {
            this.accounts = this.accounts.map((account) => account.id === accountId
                ? { ...account, ...changes }
                : account);
        },

        async addAccount() {
            this.savingAccount = true;
            this.accountResult = null;
            try {
                const { data } = await this.postJson(config.routes.accounts, { label: this.newAccountEmail });
                this.accountResult = data;
                if (data.success) this.manageAccount(data.account_id);
            } catch (error) {
                this.accountResult = { success: false, message: error.message };
            } finally {
                this.savingAccount = false;
            }
        },

        async makeDefault(accountId) {
            this.savingDefaultAccountId = accountId;
            this.accountResult = null;
            try {
                const { data } = await this.postJson(config.routes.defaultAccount, {}, accountId);
                this.accountResult = data;
                this.accountResults = { ...this.accountResults, [accountId]: data };
                if (data.success) {
                    this.defaultAccountId = accountId;
                    const account = this.accounts.find((item) => item.id === accountId) || {};
                    this.updateAccount(accountId, { connected_email: data.connected_email || this.accountEmail(account) });
                    if (accountId === this.accountId) this.context.connected_email = data.connected_email || '';
                }
            } catch (error) {
                this.accountResult = { success: false, message: error.message };
                this.accountResults = { ...this.accountResults, [accountId]: this.accountResult };
            } finally {
                this.savingDefaultAccountId = null;
            }
        },

        async postJson(url, body, accountId = this.accountId) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.head.querySelector("[name=csrf-token]")?.content || "",
                },
                body: JSON.stringify({ ...body, account_id: accountId }),
            });
            const data = await response.json().catch(() => ({}));
            if (!data.message && !response.ok) data.message = `Request failed with HTTP ${response.status}.`;
            return { response, data };
        },

        async saveGeneral() {
            this.savingGeneral = true;
            this.generalResult = null;
            try {
                const { data } = await this.postJson(config.routes.general, this.general);
                this.generalResult = data;
                if (data?.success) {
                    this.context.auth_mode = this.general.auth_mode;
                    this.context.owner_email = this.general.owner_email;
                    this.context.default_folder_id = this.general.default_folder_id;
                    const selected = this.selectedAccount();
                    const hasWriteAccess = this.general.auth_mode === 'oauth_user'
                        ? Boolean(selected.has_oauth_credentials)
                        : (this.general.auth_mode === 'service_account' ? Boolean(selected.has_service_account) : false);
                    this.context.has_write_access = hasWriteAccess;
                    this.updateAccount(this.accountId, {
                        auth_mode: this.general.auth_mode,
                        owner_email: this.general.owner_email,
                        default_folder_id: this.general.default_folder_id,
                        has_write_access: hasWriteAccess,
                    });
                }
            } catch (error) {
                this.generalResult = { success: false, message: error.message };
            } finally {
                this.savingGeneral = false;
            }
        },

        async saveOauth() {
            this.savingOauth = true;
            this.oauthResult = null;
            try {
                const { data } = await this.postJson(config.routes.oauth, this.oauth);
                this.oauthResult = data;
                if (data?.success) {
                    this.context.has_oauth_client_id = true;
                    this.context.has_oauth_client_secret = true;
                    this.context.has_oauth_refresh_token = true;
                    this.context.has_oauth_credentials = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                    this.updateAccount(this.accountId, {
                        has_oauth_client_id: true,
                        has_oauth_client_secret: true,
                        has_oauth_refresh_token: true,
                        has_oauth_credentials: true,
                        has_write_access: true,
                        connected_email: data.connected_email || this.selectedAccountLabel(),
                    });
                }
            } catch (error) {
                this.oauthResult = { success: false, message: error.message };
            } finally {
                this.savingOauth = false;
            }
        },

        async saveServiceAccount() {
            this.savingServiceAccount = true;
            this.serviceAccountResult = null;
            try {
                const { data } = await this.postJson(config.routes.serviceAccount, this.serviceAccount);
                this.serviceAccountResult = data;
                if (data?.success) {
                    this.context.has_service_account = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                    this.updateAccount(this.accountId, {
                        has_service_account: true,
                        has_write_access: true,
                        connected_email: data.connected_email || this.selectedAccountLabel(),
                    });
                }
            } catch (error) {
                this.serviceAccountResult = { success: false, message: error.message };
            } finally {
                this.savingServiceAccount = false;
            }
        },

        async runReadTest() {
            this.testingRead = true;
            this.readResult = null;
            try {
                const { data } = await this.postJson(config.routes.testRead, this.read);
                this.readResult = data;
            } catch (error) {
                this.readResult = { success: false, message: error.message };
            } finally {
                this.testingRead = false;
            }
        },

        async testAccount(accountId) {
            this.testingAccountId = accountId;
            this.accountResults = { ...this.accountResults, [accountId]: null };
            try {
                const { data } = await this.postJson(config.routes.testWrite, {}, accountId);
                this.accountResults = { ...this.accountResults, [accountId]: data };
                if (data?.success) {
                    const account = this.accounts.find((item) => item.id === accountId) || {};
                    const updates = {
                        connected_email: data.connected_email || this.accountEmail(account),
                        has_write_access: true,
                    };
                    if (account.auth_mode === 'oauth_user') {
                        updates.has_oauth_client_id = true;
                        updates.has_oauth_client_secret = true;
                        updates.has_oauth_refresh_token = true;
                        updates.has_oauth_credentials = true;
                    }
                    if (account.auth_mode === 'service_account') updates.has_service_account = true;
                    this.updateAccount(accountId, updates);
                }
                if (accountId === this.accountId) {
                    this.writeResult = data;
                    if (data?.success) {
                        this.context.has_write_access = true;
                        if (this.general.auth_mode === 'oauth_user') {
                            this.context.has_oauth_client_id = true;
                            this.context.has_oauth_client_secret = true;
                            this.context.has_oauth_refresh_token = true;
                            this.context.has_oauth_credentials = true;
                        }
                        if (this.general.auth_mode === 'service_account') this.context.has_service_account = true;
                    }
                    if (data?.connected_email !== undefined) this.context.connected_email = data.connected_email || '';
                }
            } catch (error) {
                const result = { success: false, message: error.message };
                this.accountResults = { ...this.accountResults, [accountId]: result };
                if (accountId === this.accountId) this.writeResult = result;
            } finally {
                this.testingAccountId = null;
            }
        },

        async testWrite() {
            this.testingWrite = true;
            await this.testAccount(this.accountId);
            this.testingWrite = false;
        },

        async createFolder() {
            this.creatingFolder = true;
            this.folderResult = null;
            try {
                const { data } = await this.postJson(config.routes.createFolder, this.folder);
                this.folderResult = data;
                if (data?.success && data?.folder_id) {
                    this.general.default_folder_id = data.folder_id;
                    this.context.default_folder_id = data.folder_id;
                    this.updateAccount(this.accountId, { default_folder_id: data.folder_id });
                }
            } catch (error) {
                this.folderResult = { success: false, message: error.message };
            } finally {
                this.creatingFolder = false;
            }
        },

        async smokeAccount(accountId) {
            this.smokingAccountId = accountId;
            this.accountResults = { ...this.accountResults, [accountId]: null };
            try {
                const { data } = await this.postJson(config.routes.smoke, {}, accountId);
                this.accountResults = { ...this.accountResults, [accountId]: data };
                if (accountId === this.accountId) this.writeResult = data;
            } catch (error) {
                const result = { success: false, message: error.message };
                this.accountResults = { ...this.accountResults, [accountId]: result };
                if (accountId === this.accountId) this.writeResult = result;
            } finally {
                this.smokingAccountId = null;
            }
        },

        async smokeWrite() {
            this.smokingWrite = true;
            await this.smokeAccount(this.accountId);
            this.smokingWrite = false;
        },

        displayReadContent() {
            if (!this.readResult) return '';
            return this.readResult.format === 'html'
                ? (this.readResult.plain_text || this.readResult.content || '')
                : (this.readResult.content || '');
        },
    };
};

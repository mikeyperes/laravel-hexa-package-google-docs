window.googleDocsSettings = function () {
    const configElement = document.getElementById("google-docs-settings-config");
    const config = configElement ? JSON.parse(configElement.textContent || "{}") : {};
    return {
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
        async postJson(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.head.querySelector("[name=csrf-token]")?.content || "",
                },
                body: JSON.stringify(body),
            });
            const data = await response.json().catch(() => ({}));
            return { response, data };
        },
        async saveGeneral() {
            this.savingGeneral = true; this.generalResult = null;
            try {
                const { data } = await this.postJson(config.routes.general, this.general);
                this.generalResult = data;
                if (data?.success) {
                    this.context.auth_mode = this.general.auth_mode;
                    this.context.owner_email = this.general.owner_email;
                    this.context.default_folder_id = this.general.default_folder_id;
                }
            } catch (error) {
                this.generalResult = { success: false, message: error.message };
            } finally { this.savingGeneral = false; }
        },
        async saveOauth() {
            this.savingOauth = true; this.oauthResult = null;
            try {
                const { data } = await this.postJson(config.routes.oauth, this.oauth);
                this.oauthResult = data;
                if (data?.success) {
                    this.context.has_oauth_credentials = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                }
            } catch (error) {
                this.oauthResult = { success: false, message: error.message };
            } finally { this.savingOauth = false; }
        },
        async saveServiceAccount() {
            this.savingServiceAccount = true; this.serviceAccountResult = null;
            try {
                const { data } = await this.postJson(config.routes.serviceAccount, this.serviceAccount);
                this.serviceAccountResult = data;
                if (data?.success) {
                    this.context.has_service_account = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                }
            } catch (error) {
                this.serviceAccountResult = { success: false, message: error.message };
            } finally { this.savingServiceAccount = false; }
        },
        async runReadTest() {
            this.testingRead = true; this.readResult = null;
            try {
                const { data } = await this.postJson(config.routes.testRead, this.read);
                this.readResult = data;
            } catch (error) {
                this.readResult = { success: false, message: error.message };
            } finally { this.testingRead = false; }
        },
        async testWrite() {
            this.testingWrite = true; this.writeResult = null;
            try {
                const { data } = await this.postJson(config.routes.testWrite, {});
                this.writeResult = data;
                if (data?.connected_email !== undefined) this.context.connected_email = data.connected_email || '';
            } catch (error) {
                this.writeResult = { success: false, message: error.message };
            } finally { this.testingWrite = false; }
        },
        async createFolder() {
            this.creatingFolder = true; this.folderResult = null;
            try {
                const { data } = await this.postJson(config.routes.createFolder, this.folder);
                this.folderResult = data;
                if (data?.success && data?.folder_id) {
                    this.general.default_folder_id = data.folder_id;
                    this.context.default_folder_id = data.folder_id;
                }
            } catch (error) {
                this.folderResult = { success: false, message: error.message };
            } finally { this.creatingFolder = false; }
        },
        async smokeWrite() {
            this.smokingWrite = true; this.writeResult = null;
            try {
                const { data } = await this.postJson(config.routes.smoke, {});
                this.writeResult = data;
            } catch (error) {
                this.writeResult = { success: false, message: error.message };
            } finally { this.smokingWrite = false; }
        },
        displayReadContent() {
            if (!this.readResult) return '';
            return this.readResult.format === 'html' ? (this.readResult.plain_text || this.readResult.content || '') : (this.readResult.content || '');
        },
    };
};

/**
 * Evolution API Plugin JavaScript
 */

var MauticEvolution = {
    
    /**
     * Initialize Evolution plugin functionality
     */
    init: function() {
        this.bindEvents();
        this.initTooltips();
    },
    
    /**
     * Bind event handlers
     */
    bindEvents: function() {
        // Test connection button
        mQuery(document).on('click', '#test-connection', this.testConnection);
        
        // Template preview
        mQuery(document).on('click', '#preview-template', this.previewTemplate);
        
        // Template type change
        mQuery(document).on('change', '#evolution_template_type', this.onTemplateTypeChange);
        
        // Form validation
        mQuery(document).on('submit', '.evolution-form', this.validateForm);

        // Campaign builder: instance/template selectors for WhatsApp Cloud templates
        mQuery(document).on('change', '.evolution-send-template-widget .evolution-instance-select', function() {
            MauticEvolution.reloadTemplatesForInstance(mQuery(this));
        });
        mQuery(document).on('change', '.evolution-send-template-widget .evolution-template-select', function() {
            MauticEvolution.renderTemplateVariableHints(mQuery(this));
        });

        // Initialize hints when campaign action form is injected
        mQuery(document).on('shown.bs.modal ajaxComplete', function() {
            mQuery('.evolution-send-template-widget .evolution-template-select').each(function() {
                MauticEvolution.renderTemplateVariableHints(mQuery(this));
            });
        });
    },

    templatesAjaxUrl: function(instance) {
        var base = (typeof mauticBasePath !== 'undefined' ? mauticBasePath : '');
        return base + '/s/evolution/ajax/templates/' + encodeURIComponent(instance || '');
    },

    reloadTemplatesForInstance: function($instanceSelect) {
        var $widget = $instanceSelect.closest('.evolution-send-template-widget');
        var instance = $instanceSelect.val();
        var $templateSelect = $widget.find('.evolution-template-select');
        if (!instance || !$templateSelect.length) {
            return;
        }

        $templateSelect.prop('disabled', true);
        mQuery.getJSON(MauticEvolution.templatesAjaxUrl(instance))
            .done(function(response) {
                $templateSelect.empty();
                $templateSelect.append(mQuery('<option>', { value: '', text: '' }));
                if (response.choices) {
                    mQuery.each(response.choices, function(label, value) {
                        $templateSelect.append(mQuery('<option>', { value: value, text: label }));
                    });
                }
                var catalog = response.templates || {};
                $templateSelect.attr('data-template-catalog', JSON.stringify(catalog));
                $widget.find('.evolution-template-catalog-json').val(JSON.stringify(catalog));
                MauticEvolution.renderTemplateVariableHints($templateSelect);
            })
            .fail(function() {
                if (typeof Mautic !== 'undefined' && Mautic.addInfoFlashMessage) {
                    Mautic.addInfoFlashMessage('Failed to load Evolution templates for instance');
                    Mautic.setFlashes();
                }
            })
            .always(function() {
                $templateSelect.prop('disabled', false);
            });
    },

    renderTemplateVariableHints: function($templateSelect) {
        var $widget = $templateSelect.closest('.evolution-send-template-widget');
        var $hints = $widget.find('.evolution-template-variable-hints');
        if (!$hints.length) {
            return;
        }

        var key = $templateSelect.val();
        var catalogRaw = $templateSelect.attr('data-template-catalog') || '{}';
        var catalog = {};
        try {
            catalog = JSON.parse(catalogRaw);
        } catch (e) {
            catalog = {};
        }

        var entry = catalog[key];
        if (!entry || !entry.variables || !entry.variables.length) {
            $hints.hide().empty();
            return;
        }

        var lines = ['Required template variables (use keys below in Variables):'];
        mQuery.each(entry.variables, function(_, variable) {
            var line = variable.key;
            if (variable.label) {
                line += ' — ' + variable.label;
            }
            if (variable.example) {
                line += ' (e.g. ' + variable.example + ')';
            }
            if (variable.text) {
                line += '<br><code style="white-space:pre-wrap;">' + mQuery('<div>').text(variable.text).html() + '</code>';
            }
            lines.push(line);
        });
        $hints.html(lines.join('<br>')).show();
    },
    
    /**
     * Initialize tooltips
     */
    initTooltips: function() {
        mQuery('[data-toggle="tooltip"]').tooltip();
    },
    
    /**
     * Test Evolution API connection
     */
    testConnection: function(e) {
        e.preventDefault();
        
        var button = mQuery(this);
        var originalText = button.html();
        
        // Show loading state
        button.html('<i class="fa fa-spinner fa-spin"></i> Testando...');
        button.prop('disabled', true);
        
        // Get form data
        var formData = {
            evolution_api_url: mQuery('#evolution_api_url').val(),
            evolution_api_key: mQuery('#evolution_api_key').val(),
            evolution_instance: mQuery('#evolution_instance').val()
        };
        
        mQuery.ajax({
            url: mauticAjaxUrl,
            type: 'POST',
            data: {
                action: 'plugin:evolution:testConnection',
                data: formData
            },
            success: function(response) {
                if (response.success) {
                    Mautic.showNotification('Conexão testada com sucesso!', 'success');
                    MauticEvolution.updateConnectionStatus(true);
                } else {
                    Mautic.showNotification(response.error || 'Erro ao testar conexão', 'error');
                    MauticEvolution.updateConnectionStatus(false);
                }
            },
            error: function() {
                Mautic.showNotification('Erro ao testar conexão', 'error');
                MauticEvolution.updateConnectionStatus(false);
            },
            complete: function() {
                button.html(originalText);
                button.prop('disabled', false);
            }
        });
    },
    
    /**
     * Update connection status display
     */
    updateConnectionStatus: function(connected) {
        var statusDiv = mQuery('#connection-status');
        if (statusDiv.length) {
            if (connected) {
                statusDiv.html('<div class="text-success"><i class="fa fa-check-circle"></i> Conectado</div>');
            } else {
                statusDiv.html('<div class="text-danger"><i class="fa fa-times-circle"></i> Desconectado</div>');
            }
            statusDiv.addClass('status-change');
            setTimeout(function() {
                statusDiv.removeClass('status-change');
            }, 500);
        }
    },
    
    /**
     * Preview template
     */
    previewTemplate: function(e) {
        e.preventDefault();
        
        var templateId = mQuery(this).data('template-id');
        if (!templateId) {
            // Get from URL or form
            var url = window.location.href;
            var matches = url.match(/\/(\d+)$/);
            if (matches) {
                templateId = matches[1];
            }
        }
        
        if (templateId) {
            var previewUrl = mauticBaseUrl + 's/evolution/template/preview/' + templateId;
            window.open(previewUrl, '_blank', 'width=800,height=600,scrollbars=yes');
        }
    },
    
    /**
     * Handle template type change
     */
    onTemplateTypeChange: function() {
        var type = mQuery(this).val();
        var contentField = mQuery('#evolution_template_content');
        var helpText = contentField.siblings('.help-block');
        
        // Update placeholder and help text based on type
        switch (type) {
            case 'text':
                contentField.attr('placeholder', 'Digite sua mensagem de texto aqui...');
                helpText.html('<small><i class="fa fa-info-circle"></i> Mensagem de texto simples. Use {variáveis} para personalização.</small>');
                break;
            case 'media':
                contentField.attr('placeholder', 'URL da mídia ou texto com mídia...');
                helpText.html('<small><i class="fa fa-info-circle"></i> Mensagem com mídia (imagem, vídeo, áudio). Inclua a URL da mídia.</small>');
                break;
            case 'interactive':
                contentField.attr('placeholder', 'Conteúdo interativo (botões, listas, etc.)...');
                helpText.html('<small><i class="fa fa-info-circle"></i> Mensagem interativa com botões ou listas. Use formato JSON para estrutura.</small>');
                break;
        }
    },
    
    /**
     * Validate form before submission
     */
    validateForm: function(e) {
        var form = mQuery(this);
        var isValid = true;
        var errors = [];
        
        // Validate required fields
        form.find('[required]').each(function() {
            var field = mQuery(this);
            if (!field.val().trim()) {
                isValid = false;
                errors.push('O campo ' + field.attr('name') + ' é obrigatório');
                field.addClass('has-error');
            } else {
                field.removeClass('has-error');
            }
        });
        
        // Validate URL fields
        form.find('input[type="url"]').each(function() {
            var field = mQuery(this);
            var url = field.val().trim();
            if (url && !MauticEvolution.isValidUrl(url)) {
                isValid = false;
                errors.push('URL inválida: ' + field.attr('name'));
                field.addClass('has-error');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            Mautic.showNotification(errors.join('<br>'), 'error');
        }
        
        return isValid;
    },
    
    /**
     * Validate URL format
     */
    isValidUrl: function(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    },
    
    /**
     * Show loading state for element
     */
    showLoading: function(element) {
        element.addClass('evolution-loading');
    },
    
    /**
     * Hide loading state for element
     */
    hideLoading: function(element) {
        element.removeClass('evolution-loading');
    },
    
    /**
     * Format phone number for Evolution API v2
     * Digits only, including country code, no leading '+'
     * Example: +49 170 1234567 -> 491701234567
     */
    formatPhoneNumber: function(phone) {
        var cleaned = String(phone || '').trim();
        if (cleaned.indexOf('00') === 0) {
            cleaned = cleaned.substring(2);
        }
        cleaned = cleaned.replace(/\D/g, '');
        return cleaned;
    },
    
    /**
     * Extract variables from template content
     */
    extractVariables: function(content) {
        var regex = /\{([^}]+)\}/g;
        var variables = [];
        var match;
        
        while ((match = regex.exec(content)) !== null) {
            if (variables.indexOf(match[1]) === -1) {
                variables.push(match[1]);
            }
        }
        
        return variables;
    },
    
    /**
     * Update variables field based on content
     */
    updateVariablesField: function() {
        var content = mQuery('#evolution_template_content').val();
        var variables = MauticEvolution.extractVariables(content);
        var variablesField = mQuery('#evolution_template_variables');
        
        if (variables.length > 0) {
            variablesField.val(variables.join(', '));
        }
    }
};

// Initialize when document is ready
mQuery(document).ready(function() {
    MauticEvolution.init();
    
    // Auto-update variables when content changes
    mQuery(document).on('blur', '#evolution_template_content', MauticEvolution.updateVariablesField);
});

// Export for global access
window.MauticEvolution = MauticEvolution;
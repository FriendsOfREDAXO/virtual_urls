/* virtual_urls: Dynamisches Nachladen von Spalten per AJAX */
(function () {
    'use strict';

    var isInitialized = false;

    function initProfileForm() {
        if (isInitialized) {
            return;
        }

        var tableSelect    = document.getElementById('virtual-urls-table-name');
        var relationFieldSelect = document.getElementById('virtual-urls-relation-field');
        var relTableSelect = document.getElementById('virtual-urls-relation-table');

        if (!tableSelect) {
            return;
        }

        isInitialized = true;

        var ajaxUrl = tableSelect.getAttribute('data-ajax-url');
        var csrfName = tableSelect.getAttribute('data-csrf-name');
        var csrfValue = tableSelect.getAttribute('data-csrf-value');
        var firstTableLoad = true;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function fetchTableMeta(tableName) {
            if (!tableName) {
                return Promise.resolve({ columns: [], relation_fields: {} });
            }

            var requestUrl = ajaxUrl + '&table=' + encodeURIComponent(tableName);
            if (csrfName && csrfValue) {
                requestUrl += '&' + encodeURIComponent(csrfName) + '=' + encodeURIComponent(csrfValue);
            }

            return fetch(requestUrl).then(function (response) {
                return response.json();
            });
        }

        function populateSelect(select, items, defaultLabel) {
            var emptyLabel = select.getAttribute('data-empty-label') || defaultLabel || '- Bitte wählen -';
            var allowEmpty = select.getAttribute('data-allow-empty') !== '0';
            var selectedValue = select.getAttribute('data-selected') || select.value;

            var html = allowEmpty ? '<option value="">' + emptyLabel + '</option>' : '';
            items.forEach(function (item) {
                var value = item.value;
                var label = item.label || item.value;
                var isSelected = value === selectedValue ? ' selected' : '';
                html += '<option value="' + escapeHtml(value) + '"' + isSelected + '>' + escapeHtml(label) + '</option>';
            });

            select.innerHTML = html;
            select.removeAttribute('data-selected');
        }

        function loadColumns(tableName, selects) {
            if (!tableName) {
                selects.forEach(function (select) {
                    populateSelect(select, [], '- Bitte Tabelle wählen -');
                });
                return;
            }

            fetchTableMeta(tableName)
                .then(function (meta) {
                    var columns = Array.isArray(meta.columns) ? meta.columns : [];
                    var items = columns.map(function (col) {
                        return { value: col, label: col };
                    });
                    selects.forEach(function (select) {
                        populateSelect(select, items, '- Bitte wählen -');
                    });
                })
                .catch(function () {
                    selects.forEach(function (select) {
                        select.innerHTML = '<option value="">- Fehler beim Laden -</option>';
                    });
                });
        }

        function getRelationMapFromFieldSelect() {
            if (!relationFieldSelect) {
                return {};
            }

            var rawMap = relationFieldSelect.getAttribute('data-relation-map') || '{}';
            try {
                var parsed = JSON.parse(rawMap);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (e) {
                return {};
            }
        }

        function updateRelationTableFromField() {
            if (!relationFieldSelect || !relTableSelect) {
                return;
            }

            var relationMap = getRelationMapFromFieldSelect();
            var relationField = relationFieldSelect.value;
            var relationTable = relationField && relationMap[relationField] ? relationMap[relationField] : '';

            populateSelect(relTableSelect, relationTable ? [{ value: relationTable, label: relationTable }] : [], 'Keine Relation');
            relTableSelect.value = relationTable;

            if (relationTable) {
                loadColumns(relationTable, relationSelects);
            } else {
                relationSelects.forEach(function (select) {
                    populateSelect(select, [], 'Bitte Relationstabelle wählen...');
                });
            }
        }

        var mainSelects     = Array.from(document.querySelectorAll('.virtual-urls-main-column-select'));
        var relationSelects = Array.from(document.querySelectorAll('.virtual-urls-relation-column-select'));

        // Haupt-Tabelle: Event + Initial-Load
        tableSelect.addEventListener('change', function () {
            // Bei Tabellenwechsel durch Nutzer: gespeicherte Auswahl löschen
            if (!firstTableLoad) {
                mainSelects.forEach(function (s) { s.removeAttribute('data-selected'); });

                if (relationFieldSelect) {
                    relationFieldSelect.removeAttribute('data-selected');
                    relationFieldSelect.setAttribute('data-relation-map', '{}');
                }
                if (relTableSelect) {
                    relTableSelect.removeAttribute('data-selected');
                }
                relationSelects.forEach(function (s) { s.removeAttribute('data-selected'); });
            }

            fetchTableMeta(tableSelect.value)
                .then(function (meta) {
                    var columns = Array.isArray(meta.columns) ? meta.columns : [];
                    var relationMap = meta.relation_fields && typeof meta.relation_fields === 'object' ? meta.relation_fields : {};

                    var columnItems = columns.map(function (col) {
                        return { value: col, label: col };
                    });
                    mainSelects.forEach(function (select) {
                        populateSelect(select, columnItems, '- Bitte wählen -');
                    });

                    if (relationFieldSelect) {
                        var relationItems = Object.keys(relationMap).map(function (field) {
                            return { value: field, label: field };
                        });
                        relationFieldSelect.setAttribute('data-relation-map', JSON.stringify(relationMap));
                        populateSelect(relationFieldSelect, relationItems, 'Keine Relation');
                    }

                    updateRelationTableFromField();
                })
                .catch(function () {
                    mainSelects.forEach(function (select) {
                        select.innerHTML = '<option value="">- Fehler beim Laden -</option>';
                    });
                    if (relationFieldSelect) {
                        relationFieldSelect.innerHTML = '<option value="">- Fehler beim Laden -</option>';
                    }
                })
                .finally(function () {
                    firstTableLoad = false;
                });
        });

        if (relationFieldSelect) {
            relationFieldSelect.addEventListener('change', updateRelationTableFromField);
        }

        if (tableSelect.value) {
            tableSelect.dispatchEvent(new Event('change'));
        }
    }

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('rex:ready', initProfileForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfileForm, { once: true });
    } else {
        initProfileForm();
    }
})();

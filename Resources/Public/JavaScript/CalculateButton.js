import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

if (!window.TYPO3_EDUCA_AI_TYPO3_SEO_INITIALIZED) {

  class SeoActionButton {
    constructor() {
      document.body.addEventListener('click', this.handleClick.bind(this));
    }

    handleClick(event) {
      const button = event.target.closest('[data-action="calculate-seo"]');
      if (!button || button.disabled) {
        return;
      }
      event.preventDefault();
      this.executeCalculation(button);
    }
    
    /**
     * Setzt den Wert eines Formularfeldes und löst die notwendigen Events aus,
     * damit das TYPO3 Backend die Änderung erkennt.
     * @param {string} fieldSelector - Der CSS-Selektor für das Feld.
     * @param {string|string[]} fieldValue - Der neue Wert für das Feld.
     */
    setFieldValue(fieldSelector, fieldValue) {
        const inputElement = document.querySelector(fieldSelector);
        if (!inputElement) {
            console.warn(`Form field not found: ${fieldSelector}`);
            return;
        }

        // Spezielle Behandlung für TomSelect-Felder (wie z.B. 'keywords')
        if (inputElement.tomselect) {
            // TomSelect erwartet oft ein Array von Werten. Wir nehmen an,
            // dass die AI-Antwort ein Komma-getrennter String ist.
            const values = typeof fieldValue === 'string' ? fieldValue.split(',').map(s => s.trim()).filter(Boolean) : fieldValue;
            inputElement.tomselect.setValue(values);
            return; // TomSelect kümmert sich um seine eigenen Events.
        }

        // Standard-Behandlung für Text-Inputs und Textareas
        inputElement.value = fieldValue;
        
        // ZUERST 'input' für Live-Updates (z.B. Zeichenzähler)
        inputElement.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
        
        // DANACH 'change', um die Änderung als permanent zu markieren
        inputElement.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
    }

    executeCalculation(button) {
      const dataset = button.dataset;
      const pageId = dataset.pageId;
      const allowOverride = dataset.override === 'true';

      if (!pageId) {
        console.error('Page ID not found on button!', button);
        return;
      }

      const originalButtonContent = button.innerHTML;
      document.querySelectorAll('[data-action="calculate-seo"]').forEach(btn => btn.disabled = true);
      button.innerHTML = `<core-icon icon="spinner-circle" class="fa-spin"></core-icon> ${dataset.calculatingTitle}`;

      Notification.info(dataset.calculatingTitle, dataset.calculatingMessage, 5);

      new AjaxRequest(TYPO3.settings.ajaxUrls.educa_ai_typo3_seo_calculate)
        .post({ page: pageId, override: allowOverride })
        .then(async (response) => {
          const result = await response.resolve();

          if (result.success) {
            Notification.success(result.message || dataset.successMessage, dataset.successTitle, 5);
            if (result.data) {
              for (const fieldName in result.data) {
                const fieldValue = result.data[fieldName];
                const fieldSelector = `[name="data[pages][${pageId}][${fieldName}]"]`;
                
                // Neue Helferfunktion verwenden
                this.setFieldValue(fieldSelector, fieldValue);
              }
            }
          } else {
            Notification.error(result.message || dataset.errorMessage, dataset.errorTitle, 10);
          }
        })
        .catch((error) => {
          console.error('AJAX Error:', error);
          const errorMessage = error.response?.data?.message || dataset.errorMessage;
          Notification.error(errorMessage, dataset.errorTitle, 10);
        })
        .finally(() => {
          document.querySelectorAll('[data-action="calculate-seo"]').forEach(btn => {
            btn.disabled = false;
            // Stelle nur den Inhalt des geklickten Buttons wieder her
            if (btn === button) {
              btn.innerHTML = originalButtonContent;
            }
          });
        });
    }
  }

  new SeoActionButton();
  window.TYPO3_EDUCA_AI_TYPO3_SEO_INITIALIZED = true;
}
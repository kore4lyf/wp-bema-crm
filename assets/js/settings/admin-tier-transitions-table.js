document.addEventListener("DOMContentLoaded", function () {
    const matrixTableBody = document.querySelector(".bema-crm-tm-rows");

    if (matrixTableBody) {
        const addRowButton = document.getElementById("bema-crm-tm-add-row-button");
        const availableTiers = bemaCrmData.tiers;

        function getTierSelectOptions(selectedTier = null) {
            let options = "";
            availableTiers.forEach((tier) => {
                options += `<option value="${tier}" ${
                    selectedTier === tier ? "selected" : ""
                }>${tier}</option>`;
            });
            return options;
        }

        // Handle Add Row functionality
        if (addRowButton) {
            addRowButton.addEventListener("click", function () {
                const newRow = document.createElement("tr");
                const uniqueId = Date.now();
                newRow.classList.add("bema-crm-tm-row", "is-editing");

                newRow.innerHTML = `
                    <td class="bema-crm-tm-current-tier">
                        <span class="bema-crm-tm-current-tier-text" style="display:none;"></span>
                        <select name="bema_crm_transition_matrix[${uniqueId}][current_tier]" class="bema-crm-tm-current-tier-select">
                            ${getTierSelectOptions()}
                        </select>
                    </td>
                    <td class="bema-crm-tm-next-tier">
                        <span class="bema-crm-tm-next-tier-text" style="display:none;"></span>
                        <select name="bema_crm_transition_matrix[${uniqueId}][next_tier]" class="bema-crm-tm-next-tier-select">
                            ${getTierSelectOptions()}
                        </select>
                    </td>
                    <td class="bema-crm-tm-purchase-required">
                        <span class="bema-crm-tm-purchase-required-text"  style="display:none;" >✗ No</span>
                        <input type="hidden" name="bema_crm_transition_matrix[${uniqueId}][requires_purchase]" value="0" class="bema-crm-tm-purchase-required-hidden" />
                        <input type="checkbox" class="bema-crm-tm-purchase-required-checkbox" />
                    </td>
                    <td class="bema-crm-tm-action-buttons">
                        <button type="button" class="button bema-crm-tm-save-button">Save</button>
                        <button type="button" class="button button-secondary danger-button bema-crm-tm-remove-button">Remove</button>
                    </td>
                `;
                matrixTableBody.appendChild(newRow);
            });
        }

        // Handle Edit, Save, and Remove functionality using event delegation
        document.addEventListener("click", function (e) {
            const row = e.target.closest(".bema-crm-tm-row");
            if (!row) return;

            // Handle Edit button click
            if (e.target.classList.contains("bema-crm-tm-edit-button")) {
                row.classList.add("is-editing");

                row.querySelectorAll(
                    ".bema-crm-tm-current-tier-text, .bema-crm-tm-next-tier-text, .bema-crm-tm-purchase-required-text"
                ).forEach((el) => (el.style.display = "none"));
                row.querySelectorAll(
                    ".bema-crm-tm-current-tier-select, .bema-crm-tm-next-tier-select, .bema-crm-tm-purchase-required-checkbox"
                ).forEach((el) => (el.style.display = ""));

                e.target.style.display = "none";
                row.querySelector(".bema-crm-tm-save-button").style.display = "";
                row.querySelector(".bema-crm-tm-remove-button").style.display = "";
            }

            // Handle Save button click
            if (e.target.classList.contains("bema-crm-tm-save-button")) {
                row.classList.remove("is-editing");

                const checkbox = row.querySelector(
                    ".bema-crm-tm-purchase-required-checkbox"
                );
                const hiddenInput = row.querySelector(
                    ".bema-crm-tm-purchase-required-hidden"
                );
                hiddenInput.value = checkbox.checked ? "1" : "0";

                const currentTierSelect = row.querySelector(
                    ".bema-crm-tm-current-tier-select"
                );
                const nextTierSelect = row.querySelector(".bema-crm-tm-next-tier-select");

                row.querySelector(".bema-crm-tm-current-tier-text").textContent =
                    currentTierSelect.value;
                row.querySelector(".bema-crm-tm-next-tier-text").textContent =
                    nextTierSelect.value;
                row.querySelector(".bema-crm-tm-purchase-required-text").textContent =
                    checkbox.checked ? "✓ Yes" : "✗ No";

                row.querySelectorAll(
                    ".bema-crm-tm-current-tier-text, .bema-crm-tm-next-tier-text, .bema-crm-tm-purchase-required-text"
                ).forEach((el) => (el.style.display = ""));
                row.querySelectorAll(
                    ".bema-crm-tm-current-tier-select, .bema-crm-tm-next-tier-select, .bema-crm-tm-purchase-required-checkbox"
                ).forEach((el) => (el.style.display = "none"));

                e.target.style.display = "none";
                row.querySelector(".bema-crm-tm-remove-button").style.display = "none";
                let editBtn = row.querySelector(".bema-crm-tm-edit-button");
                if (!editBtn) {
                    editBtn = document.createElement("button");
                    editBtn.type = "button";
                    editBtn.className = "button bema-crm-tm-edit-button";
                    editBtn.textContent = "Edit";
                    row.querySelector(".bema-crm-tm-action-buttons").prepend(editBtn);
                } else {
                    editBtn.style.display = "";
                }
            }

            // Handle Remove button click
            if (e.target.classList.contains("bema-crm-tm-remove-button")) {
                if (confirm("Are you sure you want to delete this transition rule?")) {
                    row.remove();
                }
            }

            // Handle Purchase Required checkbox changes
            if (e.target.classList.contains("bema-crm-tm-purchase-required-checkbox")) {
                const hiddenInput = row.querySelector(
                    ".bema-crm-tm-purchase-required-hidden"
                );
                hiddenInput.value = e.target.checked ? "1" : "0";
            }
        });
    }
});
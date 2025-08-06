document.addEventListener("DOMContentLoaded", function() {

const tierTable = document.querySelector(".tier-table");

if(tierTable) {
  const addTierButton = document.getElementById('add-tier');
  
  if (addTierButton) {
    addTierButton.addEventListener('click', function () {
      const tbody = document.getElementById('tier-rows');
      const row = document.createElement('tr');
    
      row.innerHTML = `
          <td>
              <span class="tier-name-text" style="display:none;"></span>
              <input type="text" name="bema_crm_tiers_names[]" class="regular-text tier-name" pattern="[a-zA-Z0-9\s\-]+" required />
          </td>
          <td>
              <button type="button" class="button save-tier">Save</button>
              <button type="button" class="button button-secondary danger-button remove-tier">Remove</button>
          </td>
      `;
      tbody.appendChild(row);
    });
  }
  
  document.addEventListener('click', function (e) {
    const row = e.target.closest('tr');
    if (!row) return;
  
    // Helper to escape HTML (prevent script injection in UI)
    function escapeHTML(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
  
    if (e.target.classList.contains('edit-tier')) {
        row.querySelector('.tier-name-text').style.display = 'none';
        row.querySelector('.tier-name').style.display = '';
  
        e.target.style.display = 'none';
        row.querySelector('.save-tier').style.display = '';
        row.querySelector('.remove-tier').style.display = '';
    }
  
    if (e.target.classList.contains('save-tier')) {
        const nameInput = row.querySelector('.tier-name');
        const nameSpan = row.querySelector('.tier-name-text');
        const nameVal = nameInput.value.trim();
  
        //  validation
        if (!nameVal) {
            alert('Tier name cannot be empty.');
            return;
        }
  
        if (!/^[a-zA-Z0-9\s\-]+$/.test(nameVal)) {
            alert('Only letters, numbers, spaces, and hyphens are allowed.');
            return;
        }
  
        // Check for duplicates
        const allInputs = document.querySelectorAll('.tier-name');
        const existing = Array.from(allInputs).filter(input =>
            input !== nameInput && input.value.trim().toLowerCase() === nameVal.toLowerCase()
        );
        if (existing.length > 0) {
            alert('Tier name already exists.');
            return;
        }
  
        nameSpan.innerHTML = escapeHTML(nameVal); // Prevent HTML injection
        nameInput.value = nameVal;
  
        nameInput.style.display = 'none';
        nameSpan.style.display = '';
  
        e.target.style.display = 'none';
        row.querySelector('.remove-tier').style.display = 'none';
  
        let editBtn = row.querySelector('.edit-tier');
        if (!editBtn) {
            editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'button edit-tier';
            editBtn.textContent = 'Edit';
            row.querySelector('td:last-child').prepend(editBtn);
        } else {
            editBtn.style.display = '';
        }
    }
  
    if (e.target.classList.contains('remove-tier')) {
        if (confirm('Are you sure you want to delete this tier?')) {
            row.remove();
        }
    }
  });
}
})
// Toggle sidebar on mobile
document.addEventListener("DOMContentLoaded", function () {
  // Toggle sidebar
  const sidebarToggler = document.createElement("button");
  sidebarToggler.className = "btn btn-primary d-md-none";
  sidebarToggler.innerHTML = '<i class="bi bi-list"></i>';
  sidebarToggler.style.position = "fixed";
  sidebarToggler.style.bottom = "20px";
  sidebarToggler.style.right = "20px";
  sidebarToggler.style.zIndex = "1000";
  sidebarToggler.style.borderRadius = "50%";
  sidebarToggler.style.width = "50px";
  sidebarToggler.style.height = "50px";
  sidebarToggler.style.display = "flex";
  sidebarToggler.style.alignItems = "center";
  sidebarToggler.style.justifyContent = "center";

  document.body.appendChild(sidebarToggler);

  sidebarToggler.addEventListener("click", function () {
    document.querySelector(".sidebar").classList.toggle("show");
    document.querySelector(".main-content").classList.toggle("show");
  });

  // Initialize tooltips
  const tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
  );
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Initialize popovers
  const popoverTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="popover"]')
  );
  popoverTriggerList.map(function (popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl);
  });

  // Set current date for date inputs
  const dateInputs = document.querySelectorAll(
    'input[type="date"]:not([value])'
  );
  dateInputs.forEach((input) => {
    input.valueAsDate = new Date();
  });

  // Auto-focus search inputs
  const searchInputs = document.querySelectorAll(
    'input[type="search"], input[name="search"]'
  );
  searchInputs.forEach((input) => {
    input.focus();
  });

  // Confirm before delete
  const deleteButtons = document.querySelectorAll('a[onclick*="confirm"]');
  deleteButtons.forEach((button) => {
    button.addEventListener("click", function (e) {
      if (!confirm("តើអ្នកពិតជាចង់លុបមែនទេ?")) {
        e.preventDefault();
      }
    });
  });
});

// Helper function for AJAX requests
function makeRequest(url, method = "GET", data = null) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(JSON.parse(xhr.response));
      } else {
        reject(xhr.statusText);
      }
    };
    xhr.onerror = () => reject(xhr.statusText);
    xhr.send(data ? JSON.stringify(data) : null);
  });
}

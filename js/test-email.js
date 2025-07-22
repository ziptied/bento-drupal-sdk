(function() {
  function handleTestEmailClick(event) {
    event.preventDefault();
    event.stopPropagation();
    
    const button = document.getElementById("isolated-test-email-btn");
    const messagesDiv = document.getElementById("test-email-messages");
    
    // Disable button and show loading state
    if (button) {
      button.disabled = true;
      button.textContent = "Sending...";
    }
    
    // Show loading message
    if (messagesDiv) {
      messagesDiv.innerHTML = '<div style="padding: 10px; background: #cce5ff; border: 1px solid #99ccff; border-radius: 4px; color: #0066cc;">Sending test email...</div>';
    }
    
    // Make fetch request to our endpoint
    var endpoint = (typeof Drupal !== 'undefined' && Drupal.url) ? Drupal.url('admin/config/bento/test-email') : '/admin/config/bento/test-email';
    fetch(endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest"
      },
      credentials: "same-origin"
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      // Re-enable button
      if (button) {
        button.disabled = false;
        button.textContent = "Send Test Email";
      }
      
      // Show result message
      if (messagesDiv) {
        if (data.success) {
          messagesDiv.innerHTML = '<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">' + data.message + '</div>';
        } else {
          messagesDiv.innerHTML = '<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">' + data.message + '</div>';
        }
      }
    })
    .catch(error => {
      // Re-enable button
      if (button) {
        button.disabled = false;
        button.textContent = "Send Test Email";
      }
      
      // Show error message
      if (messagesDiv) {
        messagesDiv.innerHTML = '<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">Failed to send test email. Please try again.</div>';
      }
    });
    
    return false;
  }
  
  // Attach event listener when DOM is ready
  document.addEventListener("DOMContentLoaded", function() {
    const button = document.getElementById("isolated-test-email-btn");
    if (button) {
      button.addEventListener("click", handleTestEmailClick);
    }
  });
})();
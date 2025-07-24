(function() {
  function handleTestEmailClick(event) {
    event.preventDefault();
    event.stopPropagation();
    
    const messagesDiv = document.getElementById("test-email-messages");
    
    // Show loading message
    if (messagesDiv) {
      messagesDiv.innerHTML = '<div style="padding: 10px; background: #cce5ff; border: 1px solid #99ccff; border-radius: 4px; color: #0066cc;">Sending test email...</div>';
    }
    
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
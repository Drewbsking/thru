// JavaScript to handle AJAX form submission and display confirmation
window.onload = function() {
    // Capture the URL parameters
    const params = new URLSearchParams(window.location.search);

    // Get the location parameter and update based on Site names
    const location = params.get('location');

    // Set the hidden location input field based on the parameter
    if (location) {
        document.getElementById('location').value = location;
        document.getElementById('siteLabel').innerText = location;
    } else {
        alert("Location parameter missing or invalid!");
    }

    // Attach the submit event handler for AJAX submission
    const form = document.getElementById('licenseForm');
    form.addEventListener('submit', function(event) {
        event.preventDefault();  // Prevent the default form submission

        const formData = new FormData(form);
        const action = form.action;

        // AJAX Request to submit the form without reloading
        fetch(action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(response => {
            // Display a confirmation message
            document.getElementById('confirmation').innerText = "Data successfully saved!";
            document.getElementById('confirmation').style.display = 'block';

            // Reset the form fields
            form.reset();

            // Keep the location parameter intact in the hidden field
            document.getElementById('location').value = location;

            // Hide the confirmation message after 2 seconds and get ready for the next entry
            setTimeout(() => {
                document.getElementById('confirmation').style.display = 'none';
            }, 2000);
        })
        .catch(error => {
            document.getElementById('confirmation').innerText = "Error occurred. Please try again.";
            document.getElementById('confirmation').style.display = 'block';
        });
    });
};

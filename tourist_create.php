<form action="submit_tourist.php" method="post">
    <fieldset>
        <legend>Personal Information</legend>
        <label for="passport">Passport:</label>
        <input type="text" id="passport" name="passport" required><br><br>

        <label for="id_card">ID Card:</label>
        <input type="text" id="id_card" name="id_card" required><br><br>

        <label for="emergency_contact">Emergency Contact:</label>
        <input type="text" id="emergency_contact" name="emergency_contact" required><br><br>

        <label for="birth_certificate">Birth Certificate:</label>
        <input type="text" id="birth_certificate" name="birth_certificate" required><br><br>

        <label for="address">Address:</label>
        <input type="text" id="address" name="address" required><br><br>

        <label for="citizenship">Citizenship:</label>
        <input type="text" id="citizenship" name="citizenship" required><br><br>
    </fieldset>
    
    <fieldset>
        <legend>Access Information</legend>
        <button type="button" onclick="sendAccessEmail()">Send Access Email</button>
    </fieldset>
    
    <input type="submit" value="Сохранить">
</form>

<script>
function sendAccessEmail() {
    // Email sending logic goes here
    // Example:
    console.log("Access email sent.");
}
</script>
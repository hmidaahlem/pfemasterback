<h2 style="color:#1a56db;">Welcome to AeroServe</h2>

<p>Hello {{ $user->first_name }},</p>

<p>Your account has been created successfully. You can log in using the credentials below:</p>

<hr>

<p><strong>Login (Email):</strong> {{ $user->email }}</p>
<p><strong>Password:</strong> {{ $password }}</p>
<p><strong>Role:</strong> {{ $role }}</p>

<hr>

<p>
    You can access the platform using the link below:
</p>

<a href="http://localhost:4200/login">
    Go to platform
</a>
<br><br>

<p style="color:red;">
<strong>⚠️ Important:</strong> Please change your password after your first login.
</p>

<br>

<p>Best regards,<br>AeroServe Team</p>

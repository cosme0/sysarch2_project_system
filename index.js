// Form toggle logic
// NOTE: This file expects the buttons to have ids: signUpButton and signInButton
// and expects the containers to have ids: signup and signIn.

window.addEventListener('DOMContentLoaded', () => {
    const signUpButton = document.getElementById('signUpButton');
    const signInButton = document.getElementById('signInButton');
    const signInForm = document.getElementById('signIn');
    const signUpForm = document.getElementById('signup');

    // If any expected element is missing, do nothing (prevents console crash)
    if (!signUpButton || !signInButton || !signInForm || !signUpForm) return;

    signUpButton.addEventListener('click', () => {
        signInForm.style.display = 'none';
        signUpForm.style.display = 'block';
    });

    signInButton.addEventListener('click', () => {
        signInForm.style.display = 'block';
        signUpForm.style.display = 'none';
    });
});


<?php
/**
 * AuthController - login / logout.
 */

declare(strict_types=1);

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        $this->view('login', ['title' => 'Sign in'], null);
    }

    public function login(): void
    {
        Csrf::verifyRequest();
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            Session::flash('Enter username and password.', 'danger');
            $this->redirect('login');
        }

        if (Auth::attempt($username, $password)) {
            $this->redirect('dashboard');
        }

        Session::flash('Invalid credentials.', 'danger');
        $this->redirect('login');
    }

    public function logout(): void
    {
        Csrf::verifyRequest();
        Auth::logout();
        $this->redirect('login');
    }
}

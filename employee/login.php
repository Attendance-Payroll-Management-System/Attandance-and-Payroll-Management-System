<?php
session_start();
require_once "config/db.php";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];


    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id,email,password FROM employee WHERE email=? "); //SQL jump ဖြစ်မှာဆိုးလို့
        $stmt->bind_param('s', $email); //user input write on database
        $stmt->execute();


        $result = $stmt->get_result();
        $email = $result->fetch_assoc(); //database change array
        $stmt->close();

        if ($email && $password == $email['password']) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['email'] = $email['email'];
            header('Location: attendance.php');
            exit;
        } else {
            $error = "Invalid email or password";
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>employee Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>


<body class="flex flex-col justify-center items-center bg-gray-300">

    <div class="flex flex-col gap-6">
        <div class=" flex justify-center mt-10">
            <p class="w-10 h-10 bg-purple-700/30 flex items-center rounded-xl justify-center">
                <img src="image/key.png" alt="Profile" class="w-6 h-6">
            </p>

        </div>
        <div class="text-center">
            <h1 class="text-2xl font-bold text-white">Welcome back</h1>
            <p class="text-gray-600">Sign in to your account</p>
        </div>

        <div class="bg-gray-900 text-white w-[400px] flex flex-col p-6 gap-6 rounded-2xl">
            <!-- Error -->
            <?php if (!empty($error)) : ?>
                <div class="flex gap-4 items-center p-2  bg-red-500/10 rounded-2xl ring-2 ring-red-800 text-red-500 font-semibold" <?php echo "Registration Fail!" ?>>
                    <img src="image/false.png" class="w-4 h-4 gap-4 items-center">
                    <?php echo htmlspecialchars($error); ?>
                </div>

            <?php endif; ?>


            <!-- form -->
            <form action="login.php" method="POST" class="space-y-5">

                <div class="flex flex-col">
                    <label for="email" class="">Email:</label>
                    <input type="email" id="email" name="email" placeholder="Enter your " class="p-2 rounded-2xl bg-opacity-25 bg-gray-500 " />
                </div>
                <div class="flex flex-col">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" class="p-2 rounded-2xl bg-opacity-25 bg-gray-500 " />
                </div>

                <button type="submit" class="bg-blue-500 text-center w-full text-black block font-semibold px-4 py-3 rounded-2xl shadow-md ">Sign In</button>


            </form>

        </div>
        <p class="text-center">Don't have an account?<a href="home/home.php" class="text-blue-900 font-bold">Register here</a></p>
    </div>
</body>




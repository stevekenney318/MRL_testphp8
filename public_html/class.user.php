<?php

require_once 'dbconfig.php';
require 'conf.inc.php';

class USER
{
    private $dbconnect;
    public $conn;

    public function __construct()
    {
        $database = new Database();
        $db = $database->dbConnection();
        $this->conn = $db;
    }

    public function runQuery($sql)
    {
        $stmt = $this->conn->prepare($sql);
        return $stmt;
    }

    public function lasdID()
    {
        $stmt = $this->conn->lastInsertId();
        return $stmt;
    }

    public function register($uname, $email, $upass, $code)
    {
        try
        {
            $password = md5($upass);
            $stmt = $this->conn->prepare(
                "INSERT INTO users(userName,userEmail,userPass,tokenCode)
                 VALUES(:user_name, :user_mail, :user_pass, :active_code)"
            );
            $stmt->bindparam(":user_name", $uname);
            $stmt->bindparam(":user_mail", $email);
            $stmt->bindparam(":user_pass", $password);
            $stmt->bindparam(":active_code", $code);
            $stmt->execute();
            return $stmt;
        }
        catch (PDOException $ex)
        {
            echo $ex->getMessage();
        }
    }

    public function login($email, $upass)
    {
        try
        {
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE userEmail=:email_id");
            $stmt->execute(array(":email_id" => $email));
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($stmt->rowCount() == 1)
            {
                if ($userRow['userStatus'] == "Y")
                {
                    if ($userRow['userPass'] == md5($upass))
                    {
                        $_SESSION['userSession'] = $userRow['userID'];
                        return true;
                    }
                    return false; // incorrect password
                }
                return false; // inactive user
            }

            return false; // user not found
        }
        catch (PDOException $ex)
        {
            echo $ex->getMessage();
            return false;
        }
    }

    public function is_logged_in()
    {
        return isset($_SESSION['userSession']);
    }

    public function redirect($url)
    {
        header("Location: $url");
        exit;
    }

    public function logout()
    {
        session_destroy();
        $_SESSION['userSession'] = false;
    }

    public function send_mail($email, $message, $subject)
    {
        require_once('mailer/class.phpmailer.php');

        $mail = new PHPMailer();
        $mail->IsSMTP();
        $mail->SMTPAuth = true;

        // Gmail SMTP settings
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->Username = "manliusracingleague@gmail.com";
        $mail->Password = "rvldfazxntiyaxda";

        $mail->CharSet = 'UTF-8';

        $mail->SetFrom("manliusracingleague@gmail.com", "Manlius Racing League");
        $mail->AddReplyTo("manliusracingleague@gmail.com", "Manlius Racing League");

        $mail->AddAddress($email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->MsgHTML($message);

        if (!$mail->Send()) {
            echo "MAIL ERROR: " . $mail->ErrorInfo;
            return false;
        }

        return true;
    }
} // <-- THIS was missing in your file

// this is to determine if current user is setup as an Admin in the database (yes/no for userAdmin in 'users' table)
function isAdmin($userID)
{
    $user_home = new USER();
    $stmt = $user_home->runQuery("SELECT userAdmin FROM users WHERE userID=:uid");
    $stmt->execute(array(":uid" => $userID));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row && $row['userAdmin'] == 'Y');
}

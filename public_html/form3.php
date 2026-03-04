<html>
<head>
<title>User Form</title>
<style>
body {width:600px;font-family:calibri;}
.demo-table {
	background: #d9eeff;
	width: 100%;
	border-spacing: initial;
	margin: 2px 0px;
	word-break: break-word;
	table-layout: auto;
	line-height: 1.8em;
	color: #333;
	border-radius: 4px;
	padding: 20px 40px;
}
.demo-table td {
	padding: 15px 0px;
}
.demoInputBox {
	padding: 10px;
	border: #a9a9a9 1px solid;
	border-radius: 4px;
	width:100%;
}
.btnSubmit {
	padding: 10px 30px;
	background-color: #3367b2;
	border: 0;
	color: #FFF;
	cursor: pointer;
	border-radius: 4px;
}
</style>
</head>
<body>
<form name="frmTutorial" method="post" action="">
	
	<table border="0" width="500" align="center" class="demo-table">
		
		<tr>
			
			<td>Title<br/><input type="text" class="demoInputBox" name="title" value="<?php if(!empty($_POST['title'])) echo $_POST['title']; ?>"></td>

		</tr>


		<tr>

			<td>Description<br/><textarea class="demoInputBox" name="description" cols="10"><?php if(!empty($_POST['description'])) echo $_POST['description']; ?></textarea></td>

		</tr>


		<tr>

			<td>Category<br/>

			<select name="category" class="demoInputBox">

			<option value="Single" <?php if(!empty($_POST['category']) && $_POST['category'] == "Single") { ?>selected<?php  } ?>>Single<option>

			<option value="Group" <?php if(!empty($_POST['category']) && $_POST['category'] == "Group") { ?>selected<?php  } ?>>Group<option>

			<option value="Team" <?php if(!empty($_POST['category']) && $_POST['category'] == "Team") { ?>selected<?php  } ?>>Team<option>

			<option value="All" <?php if(!empty($_POST['category']) && $_POST['category'] == "All") { ?>selected<?php  } ?>>All<option>
				
			</select>

			</td>

		</tr>

		<tr>
			<td>Tags<br/>
			<input type="checkbox" name="tags[]" value="PHP" <?php if(!empty($_POST['tags']) && in_array("PHP",$_POST['tags'])) { ?>checked<?php  } ?>> PHP
			<input type="checkbox" name="tags[]" value="HTML" <?php if(!empty($_POST['tags']) && in_array("HTML",$_POST['tags'])) { ?>checked<?php  } ?>> HTML
			<input type="checkbox" name="tags[]" value="FORM" <?php if(!empty($_POST['tags']) && in_array("FORM",$_POST['tags'])) { ?>checked<?php  } ?>> FORM
			</td>
		</tr>
		<tr>
			<td>Active<br/><input type="radio" name="status" value="1" <?php if(!empty($_POST['status'])) { ?>checked<?php  } ?>> Yes
			<input type="radio" name="status" value="0" <?php if(empty($_POST['status'])) { ?>checked<?php  } ?>> No
			</td>
		</tr>
		<tr>
			<td>
			<input type="submit" name="submit-form" value="Submit" class="btnSubmit"></td>
		</tr>
	</table>
</form>
</body>
</html>
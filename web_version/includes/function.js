// JavaScript Document

function ShowPreviousTrip(car_no) {
	var xmlhttp = new XMLHttpRequest();
	xmlhttp.onreadystatechange = function() {
		if (this.readyState == 4 && this.status == 200) {
			var ajaxResponse = this.responseText.split(";");
			
			// 0: driver_id
			// 1: car_no
			// 2: travel_date
			// 3: travel_time
			// 4: travel_odometer_from
			// 5: travel_odometer_to
			
			if (ajaxResponse[0] == "No Trip")
			{
				document.getElementById("show_previous_trip").innerHTML = ajaxResponse[0];
				
				document.getElementById("travel_odometer_from").value = "0";
			} else {
				document.getElementById("show_previous_trip").innerHTML = "Driver: " + ajaxResponse[0] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Car No: " + ajaxResponse[1] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Date: " + ajaxResponse[2] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Time: " + ajaxResponse[3] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Odometer From: " + ajaxResponse[4] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Odometer To: " + ajaxResponse[5] + "<br>";
				
				document.getElementById("travel_odometer_from").value = ajaxResponse[5];
			}
		}
	};
	xmlhttp.open("GET", "includes/functionAjax.php?runScript=showPreviousTrip&car_no=" + car_no, true);
	xmlhttp.send();
}

/*
function showSupplierDetails(po_supplier_id) {
	var xmlhttp = new XMLHttpRequest();
	xmlhttp.onreadystatechange = function() {
		if (this.readyState == 4 && this.status == 200) {
			var ajaxResponse = this.responseText.split(";");
			
			document.getElementById("po_listing_supplier_name").value = ajaxResponse[1];
			document.getElementById("po_listing_supplier_contact_person").value = ajaxResponse[2];
			document.getElementById("po_listing_supplier_contact_no").value = ajaxResponse[3];
		}
	};
	xmlhttp.open("GET", "includes/functionAjax.php?runScript=showSupplierDetails&po_supplier_id=" + po_supplier_id, true);
	xmlhttp.send();
}
*/

function getAnswer()
{
	var ans;
	ans = window.confirm('Are you sure?');
	if (ans == true) { return true; } else { return false; }
}

function ConfirmBox(msg)
{
	var ans;
	ans = window.confirm(msg);
	if (ans == true) { return true; } else { return false; }
}

function NoSubmissionTick(date, car_no, driver, tick_status) {
	var xmlhttp = new XMLHttpRequest();
	xmlhttp.onreadystatechange = function() {
		if (this.readyState == 4 && this.status == 200) {
			/*
			var ajaxResponse = this.responseText.split(";");
			
			// 0: driver_id
			// 1: car_no
			// 2: travel_date
			// 3: travel_time
			// 4: travel_odometer_from
			// 5: travel_odometer_to
			
			if (ajaxResponse[0] == "No Trip")
			{
				document.getElementById("show_previous_trip").innerHTML = ajaxResponse[0];
				
				document.getElementById("travel_odometer_from").value = "0";
			} else {
				document.getElementById("show_previous_trip").innerHTML = "Driver: " + ajaxResponse[0] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Car No: " + ajaxResponse[1] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Date: " + ajaxResponse[2] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Time: " + ajaxResponse[3] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Odometer From: " + ajaxResponse[4] + "<br>";
				document.getElementById("show_previous_trip").innerHTML += "Odometer To: " + ajaxResponse[5] + "<br>";
				
				document.getElementById("travel_odometer_from").value = ajaxResponse[5];
			}
			*/
			
			alert(this.responseText);
		}
	};
	xmlhttp.open("GET", "includes/functionAjax.php?runScript=noSubmission&date=" + date + "&car_no=" + car_no + "&driver=" + driver + "&tick_status=" + tick_status, true);
	xmlhttp.send();
}

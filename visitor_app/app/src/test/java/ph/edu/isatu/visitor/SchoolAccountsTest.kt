package ph.edu.isatu.visitor

import com.google.gson.FieldNamingPolicy
import com.google.gson.GsonBuilder
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import ph.edu.isatu.visitor.data.ApiEnvelope
import ph.edu.isatu.visitor.data.AppointmentData
import ph.edu.isatu.visitor.data.LoginData
import ph.edu.isatu.visitor.data.SignupCodeData
import ph.edu.isatu.visitor.data.TeacherDto
import ph.edu.isatu.visitor.navigation.OfficeDirections
import ph.edu.isatu.visitor.ui.filterTeachers
import ph.edu.isatu.visitor.ui.isStudentEmail
import ph.edu.isatu.visitor.ui.studentVisitPlace
import ph.edu.isatu.visitor.ui.teacherWalkInPromise

/** ISATU students in the app (SCHOOL_ACCOUNTS.md). */
class SchoolAccountsTest {
    private val gson = GsonBuilder().setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES).create()

    private inline fun <reified T> envelope(json: String): ApiEnvelope<T> {
        val type = TypeToken.getParameterized(ApiEnvelope::class.java, T::class.java).type
        return gson.fromJson(json, type)
    }

    @Test
    fun onlyTheStudentDomainIsAStudentEmail() {
        assertTrue(isStudentEmail("mika.cruz@students.isatu.edu.ph"))
        assertTrue(isStudentEmail("  Mika.Cruz@STUDENTS.ISATU.EDU.PH "))
        assertFalse(isStudentEmail("mark.lopez@isatu.edu.ph"))
        assertFalse(isStudentEmail("mika@gmail.com"))
        assertFalse(isStudentEmail("students.isatu.edu.ph"))
    }

    @Test
    fun aStudentSignInCarriesTheirClass() {
        val signedIn = envelope<LoginData>(
            """
            {"success": true, "data": {"access_token": "abc", "user": {"id": 7, "email": "mika.cruz@students.isatu.edu.ph",
              "full_name": "Mika Dela Cruz", "role": "student",
              "student": {"student_number": "2023-01845", "course_code": "IT", "course": "IT", "department": "CCI",
                          "year_level": 3, "section": "B", "label": "IT 3-B"}}}}
            """.trimIndent(),
        )
        val user = signedIn.data!!.user
        assertTrue(user.isStudent)
        assertEquals("IT 3-B", user.student?.label)
        assertEquals("2023-01845", user.student?.studentNumber)
    }

    @Test
    fun aVisitorHasNoStudentDetails() {
        val signedIn = envelope<LoginData>("""{"success": true, "data": {"user": {"id": 8, "role": "visitor", "student": null}}}""")
        assertFalse(signedIn.data!!.user.isStudent)
        assertNull(signedIn.data!!.user.student)
    }

    @Test
    fun theCodeReplySaysWhereItWent() {
        val sent = envelope<SignupCodeData>(
            """{"success": true, "data": {"verification_required": true, "request_id": 41, "email": "mika.cruz@students.isatu.edu.ph",
               "expires_at": "2026-11-07 12:18:59", "resend_after_seconds": 60}}""",
        )
        assertEquals(41L, sent.data!!.requestId)
        assertEquals(60, sent.data!!.resendAfterSeconds)
        assertNull(sent.data!!.developmentCode)
    }

    @Test
    fun aStudentVisitHasNoPass() {
        val visit = envelope<AppointmentData>(
            """{"success": true, "data": {"appointment": {"id": 9, "status": "approved", "visitor_kind": "student",
               "arrived_at": "2026-11-07 10:28:00", "qr_pass": null,
               "teacher": {"code": "T12", "name": "Prof. Elena Reyes", "short_name": "Prof. Reyes", "course": "IT", "department": "CCI"}}}}""",
        ).data!!.appointment
        assertTrue(visit.isStudentVisit)
        assertNull(visit.qrPass)
        assertEquals("2026-11-07 10:28:00", visit.arrivedAt)
    }

    @Test
    fun anOlderServerMeansAVisitor() {
        val visit = envelope<AppointmentData>("""{"success": true, "data": {"appointment": {"id": 10, "status": "approved"}}}""").data!!.appointment
        assertFalse(visit.isStudentVisit)
    }

    @Test
    fun teachersFilterByDepartmentToo() {
        val teachers = listOf(
            TeacherDto(code = "T1", name = "Prof. Elena Reyes", course = "IT", department = "CCI"),
            TeacherDto(code = "T2", name = "Mr. Jose Reyes", course = "BSED", department = "COED"),
            TeacherDto(code = "T3", name = "Dr. Ana Salazar", course = "CS", department = "CCI"),
        )
        assertEquals(listOf("T1", "T2"), filterTeachers(teachers, "reyes", "").map { it.code })
        assertEquals(listOf("T2"), filterTeachers(teachers, "reyes", "", "COED").map { it.code })
        assertEquals(listOf("T1", "T3"), filterTeachers(teachers, "", "", "CCI").map { it.code })
        assertEquals(listOf("T3"), filterTeachers(teachers, "", "CS", "CCI").map { it.code })
    }

    @Test
    fun aStudentIsToldThereIsNoPass() {
        val teacher = TeacherDto(shortName = "Prof. Reyes")
        assertFalse(teacherWalkInPromise(teacher, null, student = true).contains("pass"))
        assertTrue(teacherWalkInPromise(teacher, null).contains("pass"))
    }

    @Test
    fun theRoomReadsSmallestPlaceFirst() {
        val directions = OfficeDirections(appointmentId = 9, office = "Prof. Reyes", building = "CCI Building", floor = "2nd floor", room = "IT Faculty Room")
        assertEquals("IT Faculty Room, 2nd floor, CCI Building", studentVisitPlace(directions))
        assertEquals("CCI Building", studentVisitPlace(OfficeDirections(appointmentId = 9, office = "Prof. Reyes", building = "CCI Building")))
        assertEquals("", studentVisitPlace(null))
    }
}

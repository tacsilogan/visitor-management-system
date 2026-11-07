package ph.edu.isatu.visitor

import com.google.gson.FieldNamingPolicy
import com.google.gson.GsonBuilder
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test
import ph.edu.isatu.visitor.data.ApiEnvelope
import ph.edu.isatu.visitor.data.AppointmentData
import ph.edu.isatu.visitor.data.CampusMapData
import ph.edu.isatu.visitor.data.VisitData
import ph.edu.isatu.visitor.data.WalkingRouteDto
import ph.edu.isatu.visitor.ui.formatTimeSpan
import ph.edu.isatu.visitor.ui.statusLabel
import ph.edu.isatu.visitor.ui.visitStatusLabel

class ApiModelTest {
    private val gson = GsonBuilder()
        .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
        .create()

    @Test
    fun pendingAppointmentDoesNotNeedQrData() {
        val json = """
            {
              "success": true,
              "data": {
                "appointment": {
                  "id": 18,
                  "office": {"code": "IT", "name": "IT Department"},
                  "subject": "Enrollment concern",
                  "status": "pending_approval",
                  "scheduled_start_at": "2026-09-21 09:00:00",
                  "scheduled_end_at": "2026-09-21 09:30:00",
                  "qr_pass": null
                }
              }
            }
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        val envelope = gson.fromJson<ApiEnvelope<AppointmentData>>(json, type)
        assertEquals(18L, envelope.data?.appointment?.id)
        assertEquals("IT Department", envelope.data?.appointment?.office?.name)
        assertNull(envelope.data?.appointment?.qrPass)
        assertFalse(envelope.data?.appointment?.qrPass?.currentlyValid ?: false)
    }

    @Test
    fun approvedWalkInIncludesImmediateQrPass() {
        val json = """
            {
              "success": true,
              "data": {
                "appointment": {
                  "id": 19,
                  "visit_type": "walk_in",
                  "office": {"code": "IT", "name": "IT Department"},
                  "subject": "Technical assistance",
                  "status": "approved",
                  "scheduled_start_at": "2026-09-20 10:00:00",
                  "scheduled_end_at": "2026-09-20 11:00:00",
                  "qr_pass": {
                    "payload": "isatu-visitor://pass?token=test",
                    "valid_from": "2026-09-20 09:30:00",
                    "valid_until": "2026-09-20 11:00:00",
                    "currently_valid": true
                  }
                }
              }
            }
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        val appointment = gson.fromJson<ApiEnvelope<AppointmentData>>(json, type).data?.appointment
        assertEquals("walk_in", appointment?.visitType)
        assertEquals("approved", appointment?.status)
        assertEquals(true, appointment?.qrPass?.currentlyValid)
    }

    @Test
    fun closedWindowUsesFriendlyProductLanguage() {
        assertEquals("Appointment done", statusLabel("window_closed"))
        assertEquals("Appointment done", statusLabel("completed"))
    }

    @Test
    fun multiStopVisitParsesPassStopsAndTracking() {
        val json = """
            {
              "success": true,
              "data": {
                "visit": {
                  "id": 7,
                  "visit_code": "MV-2026-000007",
                  "visit_date": "2026-10-02",
                  "status": "checked_in",
                  "tracking_appointment_id": 31,
                  "current_stop_id": 32,
                  "qr_pass": {
                    "token": "abc",
                    "valid_from": "2026-10-02 08:30:00",
                    "valid_until": "2026-10-02 10:30:00",
                    "currently_valid": false
                  },
                  "stops": [
                    {
                      "id": 31,
                      "office": {"code": "DEANS", "name": "Dean's Office"},
                      "status": "completed",
                      "scheduled_start_at": "2026-10-02 09:00:00",
                      "scheduled_end_at": "2026-10-02 09:30:00",
                      "qr_pass": null,
                      "visit": {"id": 7, "visit_code": "MV-2026-000007", "status": "checked_in", "stop_number": 1, "stop_count": 2}
                    },
                    {
                      "id": 32,
                      "office": {"code": "IT", "name": "IT Department"},
                      "status": "checked_in",
                      "scheduled_start_at": "2026-10-02 10:00:00",
                      "scheduled_end_at": "2026-10-02 10:30:00",
                      "qr_pass": null,
                      "visit": {"id": 7, "visit_code": "MV-2026-000007", "status": "checked_in", "stop_number": 2, "stop_count": 2}
                    }
                  ]
                }
              }
            }
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            VisitData::class.java,
        ).type
        val visit = gson.fromJson<ApiEnvelope<VisitData>>(json, type).data?.visit
        assertEquals(31L, visit?.trackingAppointmentId)
        assertEquals(32L, visit?.currentStopId)
        assertEquals("abc", visit?.qrPass?.token)
        assertEquals(2, visit?.stops?.size)
        assertEquals(2, visit?.stops?.last()?.visit?.stopNumber)
        assertNull(visit?.stops?.first()?.qrPass)
    }

    @Test
    fun singleAppointmentHasNoVisit() {
        val json = """{"success": true, "data": {"appointment": {"id": 5, "status": "approved", "visit": null}}}"""
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        assertNull(gson.fromJson<ApiEnvelope<AppointmentData>>(json, type).data?.appointment?.visit)
    }

    @Test
    fun suggestedTimesShowRangeAndLength() {
        assertEquals("9:00 AM – 9:30 AM (30 min)", formatTimeSpan("2026-10-02 09:00:00", "2026-10-02 09:30:00"))
        assertEquals("On campus", visitStatusLabel("checked_in"))
    }

    private val campusMapType = com.google.gson.reflect.TypeToken.getParameterized(
        ApiEnvelope::class.java,
        CampusMapData::class.java,
    ).type

    @Test
    fun campusMapCarriesTheRecordedWalkingRoutes() {
        val json = """
            {
              "success": true,
              "data": {
                "appointment_id": 7,
                "campus_configured": true,
                "gates": [{"name": "Main Gate", "latitude": 10.685135, "longitude": 122.5121346}],
                "stops": [],
                "walking_routes": [
                  {
                    "id": 3,
                    "office_code": "IT",
                    "name": "Main Gate → IT Department",
                    "start_label": "Main Gate",
                    "distance_meters": 141.8,
                    "points": [[10.685135, 122.5121346], [10.685603, 122.5121346], [10.686029, 122.5125698]]
                  }
                ]
              }
            }
        """.trimIndent()
        val map = gson.fromJson<ApiEnvelope<CampusMapData>>(json, campusMapType).data!!
        val route = map.walkingRoutes.single()
        assertEquals(3L, route.id)
        assertEquals("IT", route.officeCode)
        assertEquals("Main Gate", route.startLabel)
        assertEquals(141.8, route.distanceMeters, 1e-9)
        assertEquals(3, route.points.size)
        assertEquals(10.686029, route.points.last()[0], 1e-9)
        assertEquals(122.5125698, route.points.last()[1], 1e-9)
    }

    @Test
    fun campusMapFromAnOlderServerHasNoWalkingRoutes() {
        val json = """{"success": true, "data": {"appointment_id": 7, "campus_configured": true, "stops": []}}"""
        val map = gson.fromJson<ApiEnvelope<CampusMapData>>(json, campusMapType).data!!
        assertEquals(emptyList<WalkingRouteDto>(), map.walkingRoutes)
        assertNull(map.destination)
    }

    @Test
    fun directionsInsideABuildingArriveWithTheCampusMap() {
        val gson = com.google.gson.GsonBuilder()
            .setFieldNamingPolicy(com.google.gson.FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
            .create()
        val stop = gson.fromJson(
            """{"appointment_id":5,"label":"Tech Support","building":"CCI Building","floor":"2nd floor","room":"Room 201",
                "visitor_message":"Take the stairs.","direction_steps":[{"text":"Go up.","photo":"uploads/directions/a.jpg"},{"text":"Turn right.","photo":""}]}""",
            ph.edu.isatu.visitor.data.CampusStopDto::class.java,
        )
        assertEquals("CCI Building", stop.building)
        assertEquals("Take the stairs.", stop.visitorMessage)
        assertEquals(2, stop.directionSteps.size)
        assertEquals("uploads/directions/a.jpg", stop.directionSteps[0].photo)

        val fromOlderServer = gson.fromJson("""{"appointment_id":5,"label":"Tech Support"}""", ph.edu.isatu.visitor.data.CampusStopDto::class.java)
        assertEquals("", fromOlderServer.building)
        assertEquals(0, fromOlderServer.directionSteps.size)
    }

    /** teachers.php, copied from the local server's reply. */
    @Test
    fun theListOfTeachersIsRead() {
        val json = """
            {"success":true,"data":{"teachers":[{
              "code":"T89","name":"Prof. Elena Reyes","short_name":"Prof. Reyes","course":"IT","department":"CCI",
              "department_name":"College of Computing and Informatics","available_now":true,"accepts_walk_ins":true,
              "can_walk_in":true,"next_free_at":"2026-11-06 01:00:00","slot_duration_minutes":30
            }],"courses":["IT"],"timezone":"Asia/Manila","server_time":"2026-11-06 00:50:00"}}
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            ph.edu.isatu.visitor.data.TeachersData::class.java,
        ).type
        val data = gson.fromJson<ApiEnvelope<ph.edu.isatu.visitor.data.TeachersData>>(json, type).data!!
        val teacher = data.teachers.single()
        assertEquals("T89", teacher.code)
        assertEquals("Prof. Reyes", teacher.shortName)
        assertEquals("College of Computing and Informatics", teacher.departmentName)
        assertEquals(true, teacher.availableNow)
        assertEquals(true, teacher.canWalkIn)
        assertEquals("2026-11-06 01:00:00", teacher.nextFreeAt)
        assertEquals(30, teacher.slotDurationMinutes)
        assertEquals(listOf("IT"), data.courses)
    }

    /** teachers.php?code=T89: what the visitor can do next when a teacher can't see them. */
    @Test
    fun oneTeacherWithFreeTimesIsRead() {
        val json = """
            {"success":true,"data":{"teacher":{
              "code":"T89","name":"Prof. Elena Reyes","short_name":"Prof. Reyes","course":"IT","department":"CCI",
              "available_now":false,"accepts_walk_ins":true,"can_walk_in":false,"next_free_at":"2026-11-06 13:30:00",
              "next_free_slots":[{"scheduled_start_at":"2026-11-06 13:30:00","scheduled_end_at":"2026-11-06 14:00:00"}],
              "fallback_office":{"code":"IT","name":"CCI IT Office"}
            }}}
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            ph.edu.isatu.visitor.data.TeacherData::class.java,
        ).type
        val teacher = gson.fromJson<ApiEnvelope<ph.edu.isatu.visitor.data.TeacherData>>(json, type).data!!.teacher
        assertEquals("2026-11-06 13:30:00", teacher.nextFreeSlots.single().scheduledStartAt)
        assertEquals("2026-11-06 14:00:00", teacher.nextFreeSlots.single().scheduledEndAt)
        assertEquals("CCI IT Office", teacher.fallbackOffice?.name)
    }

    /** A walk-in to a teacher: no pass yet, a time to answer by, and who it's with. */
    @Test
    fun aWalkInRequestToATeacherIsRead() {
        val json = """
            {"success":true,"data":{"appointment":{
              "id":44,"visit_type":"walk_in","office":{"code":"T89","name":"Prof. Elena Reyes"},
              "status":"pending_approval","scheduled_start_at":"2026-11-06 01:00:00","scheduled_end_at":"2026-11-06 01:30:00",
              "qr_pass":null,"answer_by_at":"2026-11-06 01:03:41","meeting_done_at":null,
              "teacher":{"code":"T89","name":"Prof. Elena Reyes","short_name":"Prof. Reyes","course":"IT","department":"CCI"},
              "fallback_office":{"code":"IT","name":"CCI IT Office"}
            }}}
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        val appointment = gson.fromJson<ApiEnvelope<AppointmentData>>(json, type).data!!.appointment
        assertEquals("2026-11-06 01:03:41", appointment.answerByAt)
        assertEquals("Prof. Reyes", appointment.teacher?.shortName)
        assertEquals("CCI IT Office", appointment.fallbackOffice?.name)
        assertNull(appointment.meetingDoneAt)
        assertNull(appointment.qrPass)
    }

    /** An office visit, or an older server, has none of the teacher fields. */
    @Test
    fun anOfficeVisitHasNoTeacherFields() {
        val appointment = gson.fromJson("""{"id":5,"office":{"code":"IT","name":"CCI IT Office"},"status":"approved"}""", ph.edu.isatu.visitor.data.AppointmentDto::class.java)
        assertNull(appointment.teacher)
        assertNull(appointment.answerByAt)
        assertNull(appointment.fallbackOffice)
    }
}

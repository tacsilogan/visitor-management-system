package ph.edu.isatu.visitor.navigation

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class OfficeDirectionsTest {
    private val upstairs = OfficeDirections(
        appointmentId = 1,
        office = "Tech Support",
        location = "CCI Building, 2nd floor, Room 201",
        building = "CCI Building",
        floor = "2nd floor",
        room = "Room 201",
        message = "Take the stairs near the main door. Show your pass when you reach our office.",
        steps = listOf(
            InsideStep("Enter through the main door under the CCI Building sign.", "uploads/directions/a.jpg"),
            InsideStep("Take the stairs on your left up to the 2nd floor."),
            InsideStep("Room 201 is the door with the Tech Support sign."),
        ),
    )
    private val descriptionOnly = upstairs.copy(office = "Dean's Office", floor = "3rd floor", room = "Room 305", steps = emptyList())
    private val nothingWritten = OfficeDirections(appointmentId = 2, office = "IT Department")
    private val typedLocation = OfficeDirections(appointmentId = 3, office = "IS Department", location = "CCI building, room 12")

    @Test
    fun walkingLinesLeadToTheBuilding() {
        assertEquals("CCI Building", upstairs.place)
        assertEquals("Tech Support, 2nd floor, Room 201", upstairs.detailWhileWalking)
        assertEquals("Heading to Tech Support, in the CCI Building, 2nd floor", upstairs.startLine())
        assertEquals("Next stop: Tech Support, in the CCI Building, 2nd floor", upstairs.startLine("Next stop: Tech Support"))
    }

    @Test
    fun nearTheBuildingTheDescriptionIsShownAndSaid() {
        assertEquals("Tech Support is on the 2nd floor", upstairs.title)
        assertEquals("CCI Building, Room 201", upstairs.subtitle)
        assertEquals(
            "CCI Building is just ahead. Tech Support is on the 2nd floor, Room 201. " +
                "Take the stairs near the main door. Show your pass when you reach our office.",
            upstairs.nearLine(),
        )
        assertEquals("CCI Building is just ahead", upstairs.nearNotificationTitle)
        assertEquals("Tech Support is on the 2nd floor, Room 201. Tap for directions.", upstairs.nearNotificationText)
    }

    @Test
    fun theQuestionFollowsWhetherThereAreSteps() {
        assertEquals("Are you at the building now?", upstairs.question)
        assertEquals("Are you at Dean's Office now?", descriptionOnly.question)
        assertTrue(upstairs.hasSteps)
        assertFalse(descriptionOnly.hasSteps)
    }

    @Test
    fun onlyTheLastStepAsksIfTheyAreThere() {
        assertEquals("Take the stairs on your left up to the 2nd floor.", upstairs.stepLine(1))
        assertEquals("Room 201 is the door with the Tech Support sign. Are you at Tech Support now?", upstairs.stepLine(2))
        assertEquals("a step that no longer exists says nothing", "", upstairs.stepLine(7))
    }

    @Test
    fun anOfficeWithNothingWrittenStillReadsWell() {
        assertEquals("IT Department", nothingWritten.place)
        assertEquals("", nothingWritten.where)
        assertEquals("IT Department", nothingWritten.title)
        assertEquals("", nothingWritten.subtitle)
        assertEquals("IT Department is just ahead.", nothingWritten.nearLine())
        assertEquals("Heading to IT Department", nothingWritten.startLine())
        assertEquals("Tap for directions.", nothingWritten.nearNotificationText)
        assertFalse(nothingWritten.hasGuide)
    }

    @Test
    fun aLocationTypedInTheDirectoryIsStillUsed() {
        assertEquals("CCI building, room 12", typedLocation.where)
        assertEquals("CCI building, room 12", typedLocation.subtitle)
        assertEquals("CCI building, room 12", typedLocation.detailWhileWalking)
        assertEquals("IS Department is just ahead. It's at CCI building, room 12.", typedLocation.nearLine())
    }

    @Test
    fun partOfTheAddressReadsWell() {
        val roomOnly = nothingWritten.copy(room = "Room 5")
        assertEquals("IT Department is just ahead. IT Department is in Room 5.", roomOnly.nearLine())
        val buildingOnly = nothingWritten.copy(building = "Main Hall")
        assertEquals("Main Hall is just ahead.", buildingOnly.nearLine())
        assertEquals("Heading to IT Department, in the Main Hall", buildingOnly.startLine())
        assertEquals("IT Department", buildingOnly.detailWhileWalking)
    }

    @Test
    fun leavingNeedsATapOnlyWhenTheNextPlaceIsElsewhere() {
        assertEquals("Go back to the main door of CCI Building. Are you outside now?", upstairs.leavingLine())
        assertTrue("every stop done", mustLeaveBuilding("CCI Building", null))
        assertTrue("the next stop is elsewhere", mustLeaveBuilding("CCI Building", "Admin Building"))
        assertFalse("the next stop is in the same building", mustLeaveBuilding("CCI Building", "cci building"))
        assertFalse("no building was written", mustLeaveBuilding("", null))
    }
}

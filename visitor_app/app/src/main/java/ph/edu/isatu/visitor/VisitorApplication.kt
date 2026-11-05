package ph.edu.isatu.visitor

import android.app.Application
import ph.edu.isatu.visitor.data.ApiClient
import ph.edu.isatu.visitor.data.CampusMapCache
import ph.edu.isatu.visitor.data.DirectionPhotoStore
import ph.edu.isatu.visitor.data.InstallationStore
import ph.edu.isatu.visitor.data.SecureTokenStore
import ph.edu.isatu.visitor.data.VisitorDatabase
import ph.edu.isatu.visitor.data.VisitorRepository

class VisitorApplication : Application() {
    lateinit var repository: VisitorRepository
        private set
    lateinit var directionPhotos: DirectionPhotoStore
        private set

    override fun onCreate() {
        super.onCreate()
        val tokenStore = SecureTokenStore(this)
        val installationStore = InstallationStore(this)
        val apiClient = ApiClient(tokenStore)
        directionPhotos = DirectionPhotoStore(this)
        repository = VisitorRepository(
            apiClient = apiClient,
            tokenStore = tokenStore,
            installationStore = installationStore,
            locationQueue = VisitorDatabase.get(this).locationQueue(),
            campusMapCache = CampusMapCache(this, apiClient.gson),
            directionPhotos = directionPhotos,
        )
    }
}


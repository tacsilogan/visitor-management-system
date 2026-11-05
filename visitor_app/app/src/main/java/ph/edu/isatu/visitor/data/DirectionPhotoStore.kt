package ph.edu.isatu.visitor.data

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import java.io.File
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import ph.edu.isatu.visitor.BuildConfig

/**
 * The photos in directions inside a building, kept on the phone: signal is often poor indoors, so
 * they are saved as soon as the campus map loads. Only the current visit's photos are kept, and
 * signing out removes them.
 */
class DirectionPhotoStore(context: Context) {
    private val folder = File(context.filesDir, "direction_photos")
    private val siteUrl = BuildConfig.API_BASE_URL.removeSuffix("api/v1/")
    private val lock = Mutex()
    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    /** Saves [paths] and removes every other photo. A photo that can't be fetched now is tried again later. */
    suspend fun keepOnly(paths: Set<String>) = withContext(Dispatchers.IO) {
        lock.withLock {
            val wanted = paths.filter(::isPhotoPath)
            wanted.forEach { path -> runCatching { download(path) } }
            val names = wanted.map(::fileName).toSet()
            folder.listFiles()?.filter { it.name !in names }?.forEach { it.delete() }
        }
    }

    /** The photo at [path], fetched first if the phone doesn't have it yet; null when it can't be had. */
    suspend fun load(path: String): Bitmap? = withContext(Dispatchers.IO) {
        if (!isPhotoPath(path)) return@withContext null
        val file = File(folder, fileName(path))
        if (!file.exists()) lock.withLock { runCatching { download(path) } }
        if (file.exists()) decode(file) else null
    }

    fun clear() {
        folder.listFiles()?.forEach { it.delete() }
    }

    private fun download(path: String) {
        val target = File(folder, fileName(path))
        if (target.exists()) return
        folder.mkdirs()
        val request = Request.Builder().url(siteUrl + path).build()
        client.newCall(request).execute().use { response ->
            val body = response.body
            if (!response.isSuccessful || body == null) return
            val partial = File(folder, target.name + ".part")
            partial.outputStream().use { output -> body.byteStream().copyTo(output) }
            if (!partial.renameTo(target)) partial.delete()
        }
    }

    private fun decode(file: File): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.path, bounds)
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / sample > MAX_SIDE_PIXELS) sample *= 2
        return BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sample })
    }

    private fun fileName(path: String) = path.substringAfterLast('/')

    private fun isPhotoPath(path: String) = PHOTO_PATH.matches(path)

    private companion object {
        val PHOTO_PATH = Regex("""uploads/directions/[a-f0-9]{32}\.(jpg|png|webp)""")
        const val MAX_SIDE_PIXELS = 1600
    }
}
